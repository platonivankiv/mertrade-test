<?php
/**
 * Сценарии по 5 обращениям из TASK.md, без зависимостей.
 * Запуск: php tests/scenarios.php   (код выхода 1, если что-то упало)
 * Кеш пишется во временный файл, var/cache.json не трогается.
 */

declare(strict_types=1);

require __DIR__ . '/../src/PriceResolver.php';

function readCsv(string $file): array
{
    $fh = fopen($file, 'r');
    $head = fgetcsv($fh, null, ';', '"', '');
    $rows = [];
    while (($r = fgetcsv($fh, null, ';', '"', '')) !== false) {
        $rows[] = array_combine($head, $r);
    }

    return $rows;
}

/** Резолвер на данных из data/; $cacheSeed — путь к файлу, которым заполнить кеш (или null — пустой кеш). */
function resolver(string $now = '2026-10-22 12:00:00', ?string $cacheSeed = null): PriceResolver
{
    $dir = __DIR__ . '/..';
    $prices = [];
    foreach (readCsv("$dir/data/prices.csv") as $r) {
        $prices[$r['sku']][$r['price_type']] = (float) $r['price'];
    }
    $clients  = array_column(readCsv("$dir/data/clients.csv"), null, 'client_id');
    $products = array_column(readCsv("$dir/data/products.csv"), null, 'sku');
    $rules    = json_decode((string) file_get_contents("$dir/data/discount_rules.json"), true)['rules'];

    $cacheFile = tempnam(sys_get_temp_dir(), 'price-cache-');
    file_put_contents($cacheFile, $cacheSeed !== null ? (string) file_get_contents($cacheSeed) : '{}');
    $nowDt = new DateTimeImmutable($now, new DateTimeZone('Europe/Minsk'));

    return new PriceResolver($prices, $clients, $products, $rules, new FileCache($cacheFile, $nowDt), $nowDt);
}

$failed = 0;
function check(string $name, callable $test): void
{
    global $failed;
    try {
        $test();
        echo "PASS  $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL  $name\n      " . $e->getMessage() . "\n";
    }
}
function assertSame2(float $expected, float $actual): void
{
    if (abs($expected - $actual) > 0.001) {
        throw new RuntimeException(sprintf('ожидали %.2f, получили %.2f', $expected, $actual));
    }
}

check('1. K-003 / B-105: нет дилерской цены — ошибка, а не розница', function (): void {
    try {
        $base = resolver()->basePrice('K-003', 'B-105');
    } catch (RuntimeException $e) {
        return;
    }
    throw new RuntimeException(sprintf('ожидали исключение, получили цену %.2f', $base));
});

check('2. K-001 / B-101: акция «Сентябрь» 22.10 не действует, скидка 0 %', function (): void {
    $r = resolver();
    assertSame2(0, $r->discountPercent('K-001', 'B-101', 1));
    assertSame2(14.75, $r->price('K-001', 'B-101'));
});

check('2a. акция действует в последний день valid_to (30.09)', function (): void {
    assertSame2(7, resolver('2026-09-30 23:00:00')->discountPercent('K-001', 'B-101', 1));
});

check('2b. акция не действует на следующий день (01.10)', function (): void {
    assertSame2(0, resolver('2026-10-01 00:30:00')->discountPercent('K-001', 'B-101', 1));
});

check('3. K-002 / B-106 x12: скидки не суммируются, берётся max(5, 3, 4) = 5 %', function (): void {
    $r = resolver();
    assertSame2(5, $r->discountPercent('K-002', 'B-106', 12));
    assertSame2(3.51, $r->price('K-002', 'B-106', 12));
});

check('3b. K-001 / B-106 x12: объёмная 3 % и акция 4 % не суммируются, берётся 4 %', function (): void {
    assertSame2(4, resolver()->discountPercent('K-001', 'B-106', 12));
});

check('4a. K-004 / B-103: со старым кешем сайт отдаёт 15.48 (поведение кеша, не код)', function (): void {
    assertSame2(15.48, resolver(cacheSeed: __DIR__ . '/../var/cache.orig.json')->price('K-004', 'B-103'));
});

check('4b. K-004 / B-103: после сброса кеша — OPT2 15.72 без скидки', function (): void {
    assertSame2(15.72, resolver()->price('K-004', 'B-103'));
});

check('5. B-107: K-005 (OPT1) 10.89, K-006 (OPT2) 10.28 — разные типы цен', function (): void {
    $r = resolver();
    assertSame2(10.89, $r->price('K-005', 'B-107'));
    assertSame2(10.28, $r->price('K-006', 'B-107'));
});

echo $failed === 0 ? "\nOK\n" : "\nУпало: $failed\n";
exit($failed === 0 ? 0 : 1);
