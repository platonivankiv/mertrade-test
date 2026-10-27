#!/usr/bin/env bash
# inc2: кто вызывает 503 и кого они задевают.
# Запуск из корня репозитория: bash 04/scripts/inc2_count.sh
set -euo pipefail

DIR="${1:-materials/tasks/04-support-incidents/logs/inc2-hosting-503}"
ACCESS="$DIR/access.log"
ERROR="$DIR/error.log"

# Тип клиента по User-Agent: bot / cron / user
classify='{ ua = ($0 ~ /SomeCrawlerBot/) ? "bot" : ($0 ~ /Wget/) ? "cron" : "user" }'

echo "== access.log: запросы по типу клиента и статусу =="
awk "$classify"' { c[ua " " $9]++ } END { for (k in c) print k, c[k] }' "$ACCESS" | sort

echo
echo "== 503: access.log vs error.log =="
echo "access.log 503: $(grep -c '" 503 ' "$ACCESS")"
echo "error.log строк: $(grep -c 'Entry processes limit' "$ERROR")"

echo
echo "== error.log: отказы по клиентам =="
grep -o 'client [0-9.]*' "$ERROR" | sort | uniq -c | sort -rn

echo
echo "== POST /checkout: всего / 503 (весь лог) =="
echo "всего: $(grep -c 'POST /checkout' "$ACCESS"), 503: $(grep 'POST /checkout' "$ACCESS" | grep -c '" 503 ')"

echo
echo "== POST /checkout в окнах эпизодов (09:00:00-09:04:00, 09:15:00-09:17:00) =="
grep 'POST /checkout' "$ACCESS" | awk '{
  t = substr($4, 14, 8)
  if ((t >= "09:00:00" && t < "09:04:00") || (t >= "09:15:00" && t < "09:17:00")) {
    total++; if ($9 == 503) bad++
  }
} END { printf "всего: %d, 503: %d\n", total, bad }'

echo
echo "== Бот: запросов в минуту (все статусы / только 200) =="
grep SomeCrawlerBot "$ACCESS" | awk '{
  m = substr($4, 14, 5); all[m]++; if ($9 == 200) ok[m]++
} END { for (m in all) printf "%s  всего %2d  200: %2d\n", m, all[m], ok[m] }' | sort

echo
echo "== Бот: время ответа 200 (мс), мин / макс =="
grep SomeCrawlerBot "$ACCESS" | awk '$9 == 200 {
  v = $NF; gsub(/[^0-9]/, "", v); v += 0
  if (min == "" || v < min) min = v; if (v > max) max = v
} END { print min, "/", max }'

echo
echo "== Cron-вызовы =="
grep Wget "$ACCESS" | awk '{ print substr($4, 14, 8), $7, $NF }'
