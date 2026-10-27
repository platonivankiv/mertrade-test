#!/usr/bin/env bash
# inc3: на каком задании встал обмен и как росла очередь.
# Запуск из корня репозитория: bash 04/scripts/inc3_check.sh
set -euo pipefail

DIR="${1:-materials/tasks/04-support-incidents/logs/inc3-exchange-1c}"
LOG="$DIR/exchange.log"
SNAP="$DIR/queue_snapshot.txt"

echo "== Успешные отправки =="
grep -n -- '-> 1C OK' "$LOG"

echo
echo "== Ошибки: по заданию и тексту (кол-во) =="
grep -- 'exchange.ERROR' "$LOG" | sed -E 's/.*(job #[0-9]+ [^ ]+) -> 1C (HTTP [0-9]+: \{[^}]*\}).*/\1 \2/' | sort | uniq -c

echo
echo "== Первая и последняя ошибка (строка:время:attempt) =="
grep -n -- 'exchange.ERROR' "$LOG" | sed -n '1p;$p' | sed -E 's/^([0-9]+):\[([^]]+)\].*(attempt=[0-9]+).*/\1: \2 \3/'

echo
echo "== Остановок пачки на ошибке =="
grep -c 'worker stopped batch on error' "$LOG"

echo
echo "== Строки, где растёт счётчик pending (строка: время -> значение) =="
grep -n 'pending jobs in queue' "$LOG" | awk -F: '{
  n = $NF + 0
  if (n != prev) { match($0, /\[[^]]+\]/); print $1 ": " substr($0, RSTART + 1, RLENGTH - 2) " -> " n }
  prev = n
}'

echo
echo "== Очередь в снимке: id / attempts / created_at =="
awk -F'|' 'NF >= 7 && $2 ~ /^ *[0-9]+ *$/ { gsub(/ +/, " "); print $2 "|" $5 "|" $6 }' "$SNAP"
