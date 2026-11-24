#!/usr/bin/env bash
# LAB 8: runs the WebSocket tests on a machine WITHOUT XAMPP (PHP CLI + throw-away SQLite DB + Node client).
#   bash tests/run_local_lab8.sh        (logs go to evidence/lab8/)
set -u
cd "$(dirname "$0")/.."
ROOT="$PWD"; OUT="$ROOT/evidence/lab8"; mkdir -p "$OUT"
DB="$(mktemp -u /tmp/zamove_lab8_XXXXXX.db)"; TOK="$(mktemp /tmp/lab8_tokens_XXXXXX.json)"
export ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE="$DB" ZAMOVE_ENV=test ZAMOVE_APP_KEY="$(printf 'k%.0s' {1..40})"
export ZAMOVE_WS_PORT="${WS_PORT:-8081}" ZAMOVE_WS_PING_SEC=2 ZAMOVE_WS_PONG_TIMEOUT=2 ZAMOVE_WS_ALLOWED_ORIGINS="https://localhost" TOKENS="$TOK"
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec(file_get_contents("tests/schema_sqlite_lab6.sql")); $p->exec(file_get_contents("tests/schema_sqlite_lab8.sql"));' "$DB"
php tests/lab8_seed.php "$TOK" >/dev/null
php ws/server.php >"$OUT/ws_server_log.txt" 2>&1 & WS_PID=$!
export WS_PID
trap 'kill $WS_PID 2>/dev/null; rm -f "$DB" "$TOK"' EXIT
sleep 1
node tests/lab8_ws_tests.js | tee "$OUT/lab8_ws_tests.txt"
rc=${PIPESTATUS[0]}
kill $WS_PID 2>/dev/null; sleep .3
exit $rc
