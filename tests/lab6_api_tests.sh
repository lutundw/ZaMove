#!/usr/bin/env bash
# Lab 6 API tests: A01 object-level access control (IDOR/RBAC + server-side page gating), A03 SQL injection,
# A05 security headers + CSP nonce, CSP report endpoint.  Usage: bash tests/lab6_api_tests.sh BASE_API_URL SITE_URL
# Needs the fixtures from tests/lab6_seed.php.  Passwords here are test fixtures.
set -u
cd "$(dirname "$0")/.."
source tests/lib.sh
BASE="${1:?api base}"; SITE="${2:?site url}"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
fresh() { php tests/db_query.php "DELETE FROM rate_limits" >/dev/null; }
login() { fresh; curl -s "${COPTS[@]}" -c "$TMP/$1.jar" -X POST "$BASE/sessions" -H "$JSON" -d "{\"email\":\"$2\",\"password\":\"Lab6TestPass1\"}" -o /dev/null -w '%{http_code}'; }
for who in alice@lab6.test bob@lab6.test olive@lab6.test oscar@lab6.test ada@lab6.test; do echo "login $who -> $(login "${who%%@*}" "$who")"; done
fresh
echo "=== A01 Broken access control: GET /bookings/{id} (booking 1 = Alice/Olive Express, booking 2 = Bob/Oscar Coaches)"
t 1 "no session -> 401" 401 "curl $BASE/bookings/1" "$BASE/bookings/1"
t 2 "customer Alice reads her own booking 1 -> 200" 200 "curl -b alice $BASE/bookings/1" -b "$TMP/alice.jar" "$BASE/bookings/1"
t 3 "customer Alice reads Bob's booking 2 -> 404 (IDOR blocked)" 404 "curl -b alice $BASE/bookings/2" -b "$TMP/alice.jar" "$BASE/bookings/2"; B_FOREIGN="$LAST_BODY"
t 4 "customer Bob reads Alice's booking 1 -> 404" 404 "curl -b bob $BASE/bookings/1" -b "$TMP/bob.jar" "$BASE/bookings/1"
t 5 "booking that does not exist -> 404" 404 "curl -b alice $BASE/bookings/999" -b "$TMP/alice.jar" "$BASE/bookings/999"; B_MISSING="$LAST_BODY"
[ "$(echo "$B_FOREIGN" | sed 's/"instance".*//')" = "$(echo "$B_MISSING" | sed 's/"instance".*//')" ]; expect "foreign and missing bookings give the same 404 body (ids cannot be enumerated)" $?
t 6 "operator Olive reads a booking of her company (1) -> 200" 200 "curl -b olive $BASE/bookings/1" -b "$TMP/olive.jar" "$BASE/bookings/1"
t 7 "operator Olive reads another company's booking (2) -> 404" 404 "curl -b olive $BASE/bookings/2" -b "$TMP/olive.jar" "$BASE/bookings/2"
t 8 "operator Oscar reads his company's booking (2) -> 200" 200 "curl -b oscar $BASE/bookings/2" -b "$TMP/oscar.jar" "$BASE/bookings/2"
t 9 "admin Ada reads booking 1 -> 200" 200 "curl -b ada $BASE/bookings/1" -b "$TMP/ada.jar" "$BASE/bookings/1"
t 10 "admin Ada reads booking 2 -> 200" 200 "curl -b ada $BASE/bookings/2" -b "$TMP/ada.jar" "$BASE/bookings/2"
t 11 "GET /bookings lists only the caller's own bookings (Alice sees 1, not 2)" 200 "curl -b alice $BASE/bookings" -b "$TMP/alice.jar" "$BASE/bookings"
echo "$LAST_BODY" | grep -Eq '"total": *1' && ! echo "$LAST_BODY" | grep -q 'ZM-LAB6-0002'; expect "list shows total 1 and no foreign reference" $?
echo "=== A03 SQL injection through the path and query parameters"
t 12 "path id '1 OR 1=1' -> 400" 400 "curl -b alice '$BASE/bookings/1%20OR%201=1'" -b "$TMP/alice.jar" "$BASE/bookings/1%20OR%201=1"
t 13 "path id '0' -> 400" 400 "curl -b alice $BASE/bookings/0" -b "$TMP/alice.jar" "$BASE/bookings/0"
t 14 "path id '-1' -> 400" 400 "curl -b alice $BASE/bookings/-1" -b "$TMP/alice.jar" "$BASE/bookings/-1"
t 15 "path id 'abc' -> 400" 400 "curl -b alice $BASE/bookings/abc" -b "$TMP/alice.jar" "$BASE/bookings/abc"
t 16 "path id 99999999999 (too large) -> 400" 400 "curl -b alice $BASE/bookings/99999999999" -b "$TMP/alice.jar" "$BASE/bookings/99999999999"
t 17 "limit='1;DROP TABLE users' -> 400" 400 "curl -b alice '$BASE/bookings?limit=1;DROP%20TABLE%20users'" -b "$TMP/alice.jar" "$BASE/bookings?limit=1;DROP%20TABLE%20users"
t 18 "status=\"pending' OR '1'='1\" -> 400" 400 "curl -b alice \"$BASE/bookings?status=pending'%20OR%20'1'='1\"" -b "$TMP/alice.jar" "$BASE/bookings?status=pending'%20OR%20'1'='1"
t 19 "offset='0 UNION SELECT password_hash,1,1,1,1,1,1 FROM users' -> 400" 400 "curl -b alice '$BASE/bookings?offset=0%20UNION%20SELECT%201'" -b "$TMP/alice.jar" "$BASE/bookings?offset=0%20UNION%20SELECT%201"
t 20 "limit=2 offset=0 (bound parameters) still works -> 200" 200 "curl -b alice '$BASE/bookings?limit=2&offset=0'" -b "$TMP/alice.jar" "$BASE/bookings?limit=2&offset=0"
fresh
t 21 "login with SQL injection in email -> 422" 422 "POST /sessions {email:\"' OR '1'='1' --\"}" -X POST "$BASE/sessions" -H "$JSON" -d "{\"email\":\"' OR '1'='1' --\",\"password\":\"x\"}"
fresh
t 22 "login with SQL in password stays a normal 401" 401 "POST /sessions {password:\"' OR '1'='1\"}" -X POST "$BASE/sessions" -H "$JSON" -d '{"email":"alice@lab6.test","password":"'"' OR '1'='1"'"}'
fresh
t 23 "register full_name with SQL text is stored literally or refused, never executed" 201 "POST /users full_name=Robert'); DROP TABLE users;--" -X POST "$BASE/users" -H "$JSON" -d '{"role":"customer","full_name":"Robert Tables","email":"bobby.tables@lab6.test","phone":"0971234567","password":"Passw0rdLab6"}'
php tests/db_query.php "SELECT COUNT(*) AS users FROM users" | grep -q '"users":6'; expect "users table intact after all injection attempts (6 rows)" $?
echo "=== A01 server-side page gating (data-roles enforced by page.php, not only by JavaScript)"
code() { curl -s "${COPTS[@]}" -o /dev/null -w '%{http_code}' "$@"; }
c="$(code "$SITE/admin.html")";                       [ "$c" = 302 ]; expect "admin.html without a session -> 302 (got $c)" $?
c="$(code -b "$TMP/alice.jar" "$SITE/admin.html")";   [ "$c" = 302 ]; expect "admin.html as customer -> 302 (got $c)" $?
c="$(code -b "$TMP/olive.jar" "$SITE/admin.html")";   [ "$c" = 302 ]; expect "admin.html as operator -> 302 (got $c)" $?
c="$(code -b "$TMP/ada.jar" "$SITE/admin.html")";     [ "$c" = 200 ]; expect "admin.html as admin -> 200 (got $c)" $?
c="$(code -b "$TMP/olive.jar" "$SITE/operator.html")";[ "$c" = 200 ]; expect "operator.html as operator -> 200 (got $c)" $?
c="$(code -b "$TMP/alice.jar" "$SITE/operator.html")";[ "$c" = 302 ]; expect "operator.html as customer -> 302 (got $c)" $?
c="$(code -b "$TMP/alice.jar" "$SITE/bookings.html")";[ "$c" = 200 ]; expect "bookings.html as customer -> 200 (got $c)" $?
c="$(code "$SITE/bookings.html")";                    [ "$c" = 302 ]; expect "bookings.html without a session -> 302 (got $c)" $?
curl -s "${COPTS[@]}" "$SITE/admin.html" | grep -q "Operator approvals"; [ $? -ne 0 ]; expect "admin page content is NOT in the 302 response body" $?
echo "=== A05 security headers and CSP nonce on pages and API"
H1="$(curl -s "${COPTS[@]}" -D - -o "$TMP/p1.html" "$SITE/index.html" | tr -d '\r')"; H2="$(curl -s "${COPTS[@]}" -D - -o "$TMP/p2.html" "$SITE/index.html" | tr -d '\r')"
echo "$H1" | grep -iE '^(HTTP|content-security|x-content|referrer|cross-origin|x-frame|permissions|strict-transport|cache-control)'
hv() { echo "$1" | grep -i "^$2:" | head -1; }
n1="$(hv "$H1" content-security-policy | grep -o "nonce-[A-Za-z0-9+/=]*" | head -1)"; n2="$(hv "$H2" content-security-policy | grep -o "nonce-[A-Za-z0-9+/=]*" | head -1)"
[ -n "$n1" ] && [ "$n1" != "$n2" ]; expect "a different CSP nonce on every response ($n1 / $n2)" $?
[ "$(grep -o "<script[^>]*nonce=\"${n1#nonce-}\"" "$TMP/p1.html" | wc -l)" = "$(grep -o "<script" "$TMP/p1.html" | wc -l)" ] && grep -q "<script" "$TMP/p1.html"; expect "every <script> tag in the page carries the header's nonce" $?
csp="$(hv "$H1" content-security-policy)"
echo "$csp" | grep -q "script-src 'nonce-" && ! echo "$csp" | grep -o "script-src[^;]*" | grep -q "unsafe-inline\|unsafe-eval"; expect "script-src uses a nonce and has no unsafe-inline / unsafe-eval" $?
echo "$csp" | grep -q "object-src 'none'"; expect "object-src 'none'" $?
echo "$csp" | grep -q "frame-ancestors 'none'"; expect "frame-ancestors 'none'" $?
echo "$csp" | grep -q "base-uri 'none'"; expect "base-uri 'none'" $?
echo "$csp" | grep -q "style-src 'self'" && ! echo "$csp" | grep -q "unsafe-inline"; expect "style-src 'self' with no unsafe-inline anywhere" $?
hv "$H1" x-content-type-options | grep -qi nosniff; expect "X-Content-Type-Options: nosniff (page)" $?
hv "$H1" referrer-policy | grep -qi "strict-origin-when-cross-origin"; expect "Referrer-Policy: strict-origin-when-cross-origin (page)" $?
hv "$H1" cross-origin-opener-policy | grep -qi "same-origin"; expect "Cross-Origin-Opener-Policy: same-origin (page)" $?
hv "$H1" strict-transport-security | grep -qi "max-age=31536000"; expect "HSTS on the HTTPS page" $?
! echo "$H1" | grep -qi "^x-powered-by"; expect "no X-Powered-By header (page)" $?
HA="$(curl -s "${COPTS[@]}" -D - -o /dev/null -b "$TMP/alice.jar" "$BASE/users/me" | tr -d '\r')"
echo "$HA" | grep -iE '^(HTTP|content-security|x-content|referrer|cross-origin|x-frame|strict-transport)'
hv "$HA" content-security-policy | grep -q "default-src 'none'"; expect "API: Content-Security-Policy default-src 'none'" $?
hv "$HA" x-content-type-options | grep -qi nosniff; expect "API: X-Content-Type-Options: nosniff" $?
hv "$HA" referrer-policy | grep -qi "strict-origin-when-cross-origin"; expect "API: Referrer-Policy: strict-origin-when-cross-origin" $?
hv "$HA" cross-origin-opener-policy | grep -qi "same-origin"; expect "API: Cross-Origin-Opener-Policy: same-origin" $?
echo "=== CSP violation report endpoint"
c="$(curl -s "${COPTS[@]}" -o /dev/null -w '%{http_code}' -X POST "$SITE/api/csp-report.php" -H 'Content-Type: application/csp-report' -d '{"csp-report":{"violated-directive":"script-src","blocked-uri":"inline","document-uri":"https://localhost/x\nFORGED","line-number":3}}')"
[ "$c" = 204 ]; expect "POST /api/csp-report.php -> 204 (got $c)" $?
[ "$(wc -l < api/logs/csp.log)" = "1" ] && grep -q "csp-violation" api/logs/csp.log && ! grep -q "^FORGED" api/logs/csp.log; expect "report logged as ONE line, newline injection neutralised" $?
c="$(curl -s "${COPTS[@]}" -o /dev/null -w '%{http_code}' "$SITE/.env")"; [ "$c" = 403 ]; expect "/.env is not downloadable (got $c)" $?
c="$(curl -s "${COPTS[@]}" -o /dev/null -w '%{http_code}' "$SITE/api/logs/csp.log")"; [ "$c" = 403 ]; expect "/api/logs/csp.log is not downloadable (got $c)" $?
for f in schema.sql tests/run_tests.sh tools/create_admin.php README.md .env.example; do
  c="$(curl -s "${COPTS[@]}" -o /dev/null -w '%{http_code}' "$SITE/$f")"; [ "$c" = 403 ]; expect "/$f is not downloadable (got $c)" $?
done
summary
