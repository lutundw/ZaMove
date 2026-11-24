#!/usr/bin/env bash
# LAB 8: Chromium test (needs Node + Playwright). Starts the daemon, a static server, drives two browsers, STOPS and RESTARTS the daemon.
#   bash tests/run_browser_lab8.sh      (logs and screenshots go to evidence/lab8/)
set -u
cd "$(dirname "$0")/.."
ROOT="$PWD"; OUT="$ROOT/evidence/lab8"; mkdir -p "$OUT"
DB="$(mktemp -u /tmp/zamove_lab8b_XXXXXX.db)"; TOK="$(mktemp /tmp/lab8b_tokens_XXXXXX.json)"; PIDF="$(mktemp)"
export ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE="$DB" ZAMOVE_ENV=test ZAMOVE_APP_KEY="$(printf 'k%.0s' {1..40})"
export ZAMOVE_WS_PORT=8081 ZAMOVE_WS_ALLOWED_ORIGINS="http://localhost:8121" TOKENS="$TOK" OUT="$OUT"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec(file_get_contents("tests/schema_sqlite_lab6.sql")); $p->exec(file_get_contents("tests/schema_sqlite_lab8.sql"));' "$DB"
php tests/lab8_seed.php "$TOK" >/dev/null
php -S localhost:8121 -t . >/dev/null 2>&1 & WEB=$!
php ws/server.php >"$OUT/ws_server_restart_log.txt" 2>&1 & echo $! >"$PIDF"
trap 'kill $WEB $(cat "$PIDF") 2>/dev/null; rm -f "$DB" "$TOK" "$PIDF"' EXIT
sleep 1
export STOP_DAEMON="kill \$(cat $PIDF); echo daemon stopped"
export START_DAEMON="(cd $ROOT && php ws/server.php >>$OUT/ws_server_restart_log.txt 2>&1 & echo \$! >$PIDF); sleep 0.5; echo daemon started"
export EMIT_EVENT="php -r 'require \"$ROOT/api/lib/realtime.php\"; rt_emit(db(), 1, \"booking.message\", [\"booking_id\"=>1,\"from\"=>\"Olive Operator\",\"from_id\"=>3,\"text\"=>\"Sent while the server was down\"]);'"
node tests/lab8_browser_tests.js | tee "$OUT/lab8_browser_tests.txt"
exit ${PIPESTATUS[0]}
