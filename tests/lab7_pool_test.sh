#!/usr/bin/env bash
# LAB 7 Task 4 (sandbox version): load test + connection-leak check on PHP's built-in server with 8 workers and
# persistent PDO connections. Every worker is a separate process, so the "pool" is one persistent connection per worker.
cd "$(dirname "$0")/.."
DB=/tmp/zm_pool_$$.db; PORT="${PORT:-8171}"; WORKERS=8
export ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE="$DB" ZAMOVE_ENV=test ZAMOVE_RL_OFF=1 ZAMOVE_ALLOW_HTTP=1 ZAMOVE_DB_PERSISTENT=1 ZAMOVE_DB_TIMEOUT=5
rm -f "$DB"; php tools/migrate.php up >/dev/null
php tools/seed_bench.php --users=500 --operators=10 --routes=60 --schedules=20000 --bookings=20000 >/dev/null
PHP_CLI_SERVER_WORKERS=$WORKERS php -S 127.0.0.1:$PORT -t . tests/router.php >/tmp/zm_pool_php.log 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; rm -f "$DB"' EXIT; sleep 1.5
pids() { pgrep -P "$SRV"; echo "$SRV"; }
fds()  { local n=0; for p in $(pids); do n=$((n + $(ls -l /proc/$p/fd 2>/dev/null | grep -c "$DB"))); done; echo $n; }
allfds(){ local n=0; for p in $(pids); do n=$((n + $(ls /proc/$p/fd 2>/dev/null | wc -l))); done; echo $n; }
rss()  { local k=0; for p in $(pids); do k=$((k + $(awk '/VmRSS/{print $2}' /proc/$p/status 2>/dev/null || echo 0))); done; echo $((k/1024)); }
URL="http://127.0.0.1:$PORT/api/v1/schedules?origin=$(php -r '$p=json_decode(file_get_contents("tools/bench_params.json"),true);echo urlencode($p["origin"]??"Lusaka");')&destination=$(php -r '$p=json_decode(file_get_contents("tools/bench_params.json"),true);echo urlencode($p["destination"]??"Kitwe");')&limit=20"
echo "Workers (processes): $WORKERS   persistent connections: ZAMOVE_DB_PERSISTENT=1   URL: $URL"
curl -s -o /dev/null -w "warm-up request: HTTP %{http_code}\n" "$URL"
echo "BEFORE load: db file handles held by workers = $(fds), all open descriptors = $(allfds), total worker memory = $(rss) MB"
pass=0; fail=0; prev=$(allfds)
for c in 1 10 50 100; do
  echo "### load: $c concurrent connections, 8 seconds"
  out=$(node tools/loadtest.js "$URL" $c 8); echo "$out"
  echo "  during/after: db handles = $(fds), descriptors = $(allfds), memory = $(rss) MB"
  ok=$(echo "$out" | php -r '$j=json_decode(stream_get_contents(STDIN),true);echo ($j["errors"]==0 && ($j["status"]["200"]??0)==$j["requests"] && $j["requests"]>0)?1:0;')
  if [ "$ok" = 1 ]; then echo "RESULT: PASS ($c connections: every request answered 200, zero errors)"; pass=$((pass+1)); else echo "RESULT: FAIL ($c connections)"; fail=$((fail+1)); fi
done
sleep 2
AFTER=$(fds); BEFORE_MAX=$((WORKERS + 1))
echo "AFTER all load (~ 40 000 requests): db file handles = $AFTER, all descriptors = $(allfds) (was $prev), memory = $(rss) MB"
if [ "$AFTER" -le "$BEFORE_MAX" ]; then echo "RESULT: PASS (connection reuse: $AFTER database handles for $WORKERS workers + the parent, not one per request)"; pass=$((pass+1)); else echo "RESULT: FAIL (handles grew to $AFTER)"; fail=$((fail+1)); fi
if [ "$(allfds)" -le $((prev + WORKERS)) ]; then echo "RESULT: PASS (no descriptor leak: $(allfds) now vs $prev before the load)"; pass=$((pass+1)); else echo "RESULT: FAIL (descriptors grew from $prev to $(allfds))"; fail=$((fail+1)); fi
echo "SUMMARY: $pass passed, $fail failed"; [ $fail = 0 ]
