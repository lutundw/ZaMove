#!/usr/bin/env bash
# Runs the bypass tests WITHOUT XAMPP: PHP's built-in web server plus a
# throw-away SQLite database. Use this on any machine that has the PHP CLI
# (pdo_sqlite enabled). For the real MySQL setup use run_tests.sh with your
# XAMPP URL instead.
set -e
cd "$(dirname "$0")/.."
DB="$(mktemp -u /tmp/zamove_test_XXXXXX.db)"
PORT="${PORT:-8099}"

php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec(file_get_contents("tests/schema_sqlite.sql"));' "$DB"
ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE="$DB" php -S "127.0.0.1:$PORT" -t . >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; rm -f "$DB"' EXIT
sleep 1

ZAMOVE_SQLITE="$DB" bash tests/run_tests.sh "http://127.0.0.1:$PORT/api/auth/register.php"
