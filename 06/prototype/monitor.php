<?php

declare(strict_types=1);

// Мониторинг обменов с 1С (прототип к заданию 06).
// Только читает очередь обмена и итоги выгрузок касс, печатает алерты.
// Запуск: php monitor.php [--data=data/incident] [--now="2026-10-22 08:20"]
// Код возврата: 0 — замечаний нет, 1 — есть алерты.

// Время в данных — местное, поэтому пояс задаём явно, а не берём из php.ini.
date_default_timezone_set('Europe/Minsk');

const CRITICAL = 'critical'; // сразу в чат
const SUMMARY = 'summary';   // в чат без срочности, разбор утром

$opts = getopt('', ['data:', 'now:']);
$dataDir = $opts['data'] ?? __DIR__ . '/data/incident';
$now = new DateTimeImmutable($opts['now'] ?? 'now');
$config = json_decode((string) file_get_contents(__DIR__ . '/shops.json'), true, 512, JSON_THROW_ON_ERROR);

$alerts = [];

try {
    $jobs = json_decode((string) @file_get_contents("$dataDir/queue.json"), true, 512, JSON_THROW_ON_ERROR);
    $alerts = array_merge($alerts, checkQueue($jobs, $now, $config));
} catch (JsonException $e) {
    $alerts[] = [CRITICAL, "Не удалось прочитать очередь обмена ($dataDir/queue.json): {$e->getMessage()}"];
}

$rows = readCsv("$dataDir/retail_exchange.csv");
if ($rows === null) {
    $alerts[] = [CRITICAL, "Не удалось прочитать итоги выгрузок касс ($dataDir/retail_exchange.csv)"];
} else {
    $alerts = array_merge($alerts, checkRetail($rows, $now, $config));
}

echo 'Мониторинг обменов с 1С, сейчас ' . $now->format('Y-m-d H:i') . PHP_EOL;
if ($alerts === []) {
    echo 'Всё в порядке: очередь движется, отчёты магазинов на месте.' . PHP_EOL;
    exit(0);
}
foreach ([CRITICAL => 'СРОЧНО (сразу в чат)', SUMMARY => 'НА РАЗБОР УТРОМ'] as $level => $title) {
    $lines = array_filter($alerts, fn(array $a) => $a[0] === $level);
    if ($lines === []) {
        continue;
    }
    echo PHP_EOL . $title . ':' . PHP_EOL;
    foreach ($lines as [, $text]) {
        echo "  - $text" . PHP_EOL;
    }
}
exit(1);

/** Очередь B2B-заказов: самое старое pending и задания с большим числом попыток. */
function checkQueue(array $jobs, DateTimeImmutable $now, array $config): array
{
    $alerts = [];
    $pending = array_values(array_filter($jobs, fn(array $j) => $j['status'] === 'pending'));
    usort($pending, fn(array $a, array $b) => strcmp($a['created_at'], $b['created_at']));

    if ($pending !== []) {
        $oldest = $pending[0];
        $minutes = minutesBetween(new DateTimeImmutable($oldest['created_at']), $now);
        if ($minutes > $config['pending_max_minutes']) {
            $alerts[] = [CRITICAL, sprintf(
                'Очередь стоит: заданий в pending — %d, самое старое #%d %s ждёт %d мин (порог %d)',
                count($pending), $oldest['id'], $oldest['order_no'], $minutes, $config['pending_max_minutes'],
            )];
        }
    }

    foreach ($jobs as $job) {
        if ($job['status'] !== 'done' && $job['attempts'] > $config['max_attempts']) {
            $alerts[] = [CRITICAL, sprintf(
                'Задание #%d %s: попыток — %d (порог %d), последняя ошибка: %s',
                $job['id'], $job['order_no'], $job['attempts'], $config['max_attempts'], $job['last_error'] ?? '—',
            )];
        }
    }

    return $alerts;
}

/** Розница: отчёт каждого работающего магазина за день и тихо пропущенные чеки. */
function checkRetail(array $rows, DateTimeImmutable $now, array $config): array
{
    $alerts = [];

    // До дедлайна проверяем вчерашний день, после — сегодняшний.
    $day = $now->format('H:i') >= $config['report_deadline'] ? $now : $now->modify('-1 day');
    $date = $day->format('Y-m-d');

    if (!in_array($date, $config['holidays'], true)) {
        foreach ($config['shops'] as $shopId => $shop) {
            if (!in_array((int) $day->format('N'), $shop['days'], true)) {
                continue; // выходной по расписанию
            }
            $loaded = array_filter($rows, fn(array $r) => $r['shop'] === (string) $shopId
                && str_starts_with($r['received_at'], $date) && (int) $r['created'] > 0);
            if ($loaded === []) {
                $alerts[] = [SUMMARY, sprintf(
                    '%s: нет отчёта о розничных продажах за %s (проверка после %s)',
                    $shop['name'], $day->format('d.m.Y'), $config['report_deadline'],
                )];
            }
        }
    }

    // Пропущенные чеки — ошибка в любой день и при любом errors, смотрим последние сутки.
    $since = $now->modify('-1 day');
    foreach ($rows as $r) {
        $received = new DateTimeImmutable($r['received_at']);
        if ($received > $since && $received <= $now && (int) $r['skipped'] > 0) {
            $name = $config['shops'][$r['shop']]['name'] ?? "Магазин {$r['shop']}";
            $alerts[] = [SUMMARY, sprintf(
                '%s, смена %s (%s): пропущено %d из %d чеков, errors=%d',
                $name, $r['shift'], $r['received_at'], $r['skipped'], $r['receipts'], $r['errors'],
            )];
        }
    }

    return $alerts;
}

function minutesBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
{
    return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
}

/** @return list<array<string,string>>|null */
function readCsv(string $path): ?array
{
    $fh = @fopen($path, 'r');
    if ($fh === false) {
        return null;
    }
    $header = fgetcsv($fh, escape: '');
    $rows = [];
    while (($line = fgetcsv($fh, escape: '')) !== false) {
        if ($line !== [null] && count($line) === count($header)) {
            $rows[] = array_combine($header, $line);
        }
    }
    fclose($fh);

    return $rows;
}
