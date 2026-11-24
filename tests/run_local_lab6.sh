#!/usr/bin/env bash
# Runs the Lab 6 tests on a machine WITHOUT XAMPP: PHP built-in server + throw-away SQLite DB + TLS 1.3 terminator.
#   bash tests/run_local_lab6.sh        (logs go to evidence/lab6/)
# The browser tests (Playwright + Chromium) are run separately: node tests/lab6_browser_tests.js
set -u
cd "$(dirname "$0")/.."
ROOT="$PWD"; OUT="$ROOT/evidence/lab6"; mkdir -p "$OUT"
DB="$(mktemp -u /tmp/zamove_lab6_XXXXXX.db)"; TMP="$(mktemp -d)"
PHP_PORT="${PHP_PORT:-8121}"; TLS_PORT="${TLS_PORT:-8453}"; HTTP_PORT="${HTTP_PORT:-8090}"
openssl req -x509 -newkey rsa:2048 -nodes -days 7 -subj "/CN=localhost" -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" \
  -keyout "$TMP/key.pem" -out "$TMP/cert.pem" 2>/dev/null
php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec(file_get_contents("tests/schema_sqlite_lab6.sql"));' "$DB"
export ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE="$DB"
php tests/lab6_seed.php >/dev/null
rm -f api/logs/csp.log
ZAMOVE_TRUST_PROXY=1 ZAMOVE_HTTPS_PORT="$TLS_PORT" php -S "127.0.0.1:$PHP_PORT" -t . tests/router.php >"$TMP/php.log" 2>&1 & PHP_PID=$!
TLS_CERT="$TMP/cert.pem" TLS_KEY="$TMP/key.pem" TLS_PORT="$TLS_PORT" HTTP_PORT="$HTTP_PORT" PHP_PORT="$PHP_PORT" node tests/tls13_proxy.js >"$TMP/proxy.log" 2>&1 & NODE_PID=$!
trap 'kill $PHP_PID $NODE_PID 2>/dev/null; rm -rf "$TMP" "$DB"' EXIT
sleep 1.5
export CURL_OPTS="--cacert $TMP/cert.pem"
rc=0
php tests/lab6_unit_tests.php | tee "$OUT/lab6_unit.txt" | tail -1;                                         [ "${PIPESTATUS[0]}" = 0 ] || rc=1
bash tests/lab6_api_tests.sh "https://localhost:$TLS_PORT/api/v1" "https://localhost:$TLS_PORT" | tee "$OUT/lab6_api.txt" | tail -1; [ "${PIPESTATUS[0]}" = 0 ] || rc=1
exit $rc
