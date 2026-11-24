#!/usr/bin/env bash
# =============================================================================
# Runs ALL Lab 4 / Lab 5 tests on a machine WITHOUT XAMPP:
#   PHP built-in server + throw-away SQLite DB + a TLS 1.3-only terminator (Node.js).
# Needs: php (pdo_sqlite, curl), node, openssl, curl.  Optional: mkcert certificate.
#
#   bash tests/run_local_lab45.sh                       (generates a self-signed certificate)
#   TLS_CERT=localhost.pem TLS_KEY=localhost-key.pem CA=rootCA.pem bash tests/run_local_lab45.sh   (mkcert)
#
# Logs are written to evidence/lab45/.  For the real MySQL setup, run the individual
# scripts against your XAMPP URL instead (see INTEGRATION.md).
# =============================================================================
set -u
cd "$(dirname "$0")/.."
ROOT="$PWD"; OUT="$ROOT/evidence/lab45"; mkdir -p "$OUT"
DB="$(mktemp -u /tmp/zamove_lab45_XXXXXX.db)"
PHP_PORT="${PHP_PORT:-8111}"; TLS_PORT="${TLS_PORT:-8443}"; HTTP_PORT="${HTTP_PORT:-8080}"
TMP="$(mktemp -d)"
if [ -z "${TLS_CERT:-}" ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 7 -subj "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" -keyout "$TMP/key.pem" -out "$TMP/cert.pem" 2>/dev/null
  TLS_CERT="$TMP/cert.pem"; TLS_KEY="$TMP/key.pem"; CA="$TMP/cert.pem"
fi

fresh() { php tests/db_query.php "DELETE FROM rate_limits" >/dev/null; }

php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec(file_get_contents("tests/schema_sqlite_lab45.sql"));' "$DB"
export ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE="$DB"
php tests/db_query.php "INSERT INTO bookings (booking_reference, customer_id, operator_id, booking_type, schedule_id, booking_status, total_amount) VALUES ('ZM-20261107-0001', 1, 1, 'passenger', 1, 'pending', 520.00)" >/dev/null

ZAMOVE_TRUST_PROXY=1 ZAMOVE_HTTPS_PORT="$TLS_PORT" php -S "127.0.0.1:$PHP_PORT" -t . tests/router.php >"$TMP/php.log" 2>&1 & PHP_PID=$!
TLS_CERT="$TLS_CERT" TLS_KEY="$TLS_KEY" TLS_PORT="$TLS_PORT" HTTP_PORT="$HTTP_PORT" PHP_PORT="$PHP_PORT" node tests/tls13_proxy.js >"$TMP/proxy.log" 2>&1 & NODE_PID=$!
trap 'kill $PHP_PID $NODE_PID 2>/dev/null; rm -rf "$TMP" "$DB"' EXIT
sleep 1.5

BASE="https://localhost:$TLS_PORT/api/v1"; export CURL_OPTS="--cacert $CA"; export ZAMOVE_CA="$CA"
rc=0
fresh; bash tests/lab4_validation_tests.sh "$BASE"                | tee "$OUT/lab4_validation.txt"   | tail -1; [ "${PIPESTATUS[0]}" = 0 ] || rc=1
fresh; php tests/contract_check.php "$BASE"                       | tee "$OUT/lab4_contract.txt"     | tail -1; [ "${PIPESTATUS[0]}" = 0 ] || rc=1
fresh; bash tests/lab4_ratelimit_test.sh "$BASE"                  | tee "$OUT/lab4_ratelimit.txt"    | tail -1; [ "${PIPESTATUS[0]}" = 0 ] || rc=1
fresh; bash tests/lab5_session_tests.sh "$BASE"                   | tee "$OUT/lab5_session.txt"      | tail -1; [ "${PIPESTATUS[0]}" = 0 ] || rc=1
bash tests/lab5_tls_checks.sh "$BASE" "http://localhost:$HTTP_PORT/api/v1" "$CA" | tee "$OUT/lab5_tls.txt" | tail -1; [ "${PIPESTATUS[0]}" = 0 ] || rc=1
PRE_REQUEST="php tests/db_query.php \"DELETE FROM rate_limits\"" bash tests/profile_performance.sh "$BASE" 30 | tee "$OUT/profile.txt" | tail -1
echo "Logs: $OUT"; exit $rc
