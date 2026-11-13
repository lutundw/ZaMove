#!/usr/bin/env bash
# =============================================================================
# Lab 5, Tasks 2, 3 and 4 - session cookie flags, authenticated routes (401),
# explicit logout and server-side revocation.
#
# Needs the PHP CLI (php tests/db_query.php) to look inside the sessions table.
# On XAMPP use  C:\xampp\php\php.exe  (or add it to PATH in Git Bash).
#
# Usage:  CURL_OPTS="--cacert rootCA.pem" bash tests/lab5_session_tests.sh https://localhost/zamove/api/v1
# Run on a fresh minute (login is limited to 5 per minute).
# =============================================================================
BASE="${1:-https://localhost/api/v1}"
source "$(dirname "$0")/lib.sh"
cd "$(dirname "$0")/.."
PHP="${PHP:-php}"
JAR="$(mktemp)"; OLD="$(mktemp)"; trap 'rm -f "$JAR" "$OLD"' EXIT
RUN="$(date +%s)"; EMAIL="session.test.$RUN@example.com"; PASS='Sunrise2026'
q() { "$PHP" tests/db_query.php "$1"; }

echo "Target: $BASE"
echo "Started: $(date '+%Y-%m-%d %H:%M:%S %z')"
echo "========================================================================"

t S00 "Create a test account" 201 \
  "curl -X POST $BASE/users -H '$JSON' -d '{... \"email\":\"$EMAIL\" ...}'" \
  -X POST "$BASE/users" -H "$JSON" -d "{\"role\":\"customer\",\"full_name\":\"Session Tester\",\"email\":\"$EMAIL\",\"phone\":\"0961234567\",\"password\":\"$PASS\"}"

SHOW_HEADERS=1 t S01 "Login - inspect the Set-Cookie header" 200 \
  "curl -i -c jar -X POST $BASE/sessions -H '$JSON' -d '{\"email\":\"$EMAIL\",\"password\":\"***\"}'" \
  -c "$JAR" -X POST "$BASE/sessions" -H "$JSON" -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}"
COOKIE="$(printf '%s\n' "$LAST_HEADERS" | grep -i '^set-cookie:')"
echo "Cookie header checks:"
for flag in "__Host-zamove_sid=" "HttpOnly" "secure" "SameSite=Strict" "path=/"; do
  printf '%s' "$COOKIE" | grep -qi -- "$flag"; expect "Set-Cookie contains '$flag'" $?
done
printf '%s' "$COOKIE" | grep -qi 'domain='; r=$?; [ $r = 0 ] && r=1 || r=0; expect "Set-Cookie has no Domain attribute (host-only)" $r
TOKEN="$(printf '%s' "$COOKIE" | sed -E 's/.*__Host-zamove_sid=([a-f0-9]+).*/\1/I')"
printf '%s' "$LAST_BODY" | grep -q "$TOKEN"; r=$?; [ $r = 0 ] && r=1 || r=0; expect "session id is NOT in the JSON body (only in the cookie)" $r
[ "${#TOKEN}" = 64 ]; expect "session id is 64 hex characters (256 bits)" $?
cp "$JAR" "$OLD"                                     # keep a copy of the live cookie for the replay test

echo "Session store after login (only a hash of the id is stored):"
q "SELECT substr(session_id_hash,1,16) AS id_hash_prefix, length(session_id_hash) AS hash_length, user_id, created_at, expires_at FROM sessions"
echo "------------------------------------------------------------------------"

t S02 "Authenticated route with the cookie" 200 \
  "curl -b jar $BASE/users/me" \
  -b "$JAR" "$BASE/users/me"

t S03 "Authenticated route WITHOUT a cookie" 401 \
  "curl $BASE/users/me" \
  "$BASE/users/me"

FORGED="$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
t S04 "Forged cookie (64 random hex characters)" 401 \
  "curl -H 'Cookie: __Host-zamove_sid=$FORGED' $BASE/users/me" \
  -H "Cookie: __Host-zamove_sid=$FORGED" "$BASE/users/me"

t S05 "Malformed cookie value" 401 \
  "curl -H \"Cookie: __Host-zamove_sid=' OR 1=1--\" $BASE/users/me" \
  -H "Cookie: __Host-zamove_sid=' OR 1=1--" "$BASE/users/me"

SHOW_HEADERS=1 t S06 "Logout - server revokes the session and expires the cookie" 200 \
  "curl -i -b jar -c jar -X DELETE $BASE/sessions/current" \
  -b "$JAR" -c "$JAR" -X DELETE "$BASE/sessions/current"
printf '%s\n' "$LAST_HEADERS" | grep -i '^set-cookie:' | grep -qi '1970'; expect "Set-Cookie expires the cookie (date in 1970)" $?

echo "Session store after logout:"
q "SELECT substr(session_id_hash,1,16) AS id_hash_prefix, user_id FROM sessions"
echo "------------------------------------------------------------------------"

t S07 "REPLAY the cookie captured BEFORE logout (an attacker who copied it)" 401 \
  "curl -b old-cookie-jar $BASE/users/me" \
  -b "$OLD" "$BASE/users/me"

t S08 "Log in again for the timeout tests" 200 \
  "curl -c jar -X POST $BASE/sessions -H '$JSON' -d '{\"email\":\"$EMAIL\",\"password\":\"***\"}'" \
  -c "$JAR" -X POST "$BASE/sessions" -H "$JSON" -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}"

echo "Simulate an expired session (absolute timeout): set expires_at to the year 2000"
q "UPDATE sessions SET expires_at = '2000-01-01 00:00:00'"
t S09 "Request with the expired session" 401 \
  "curl -b jar $BASE/users/me" \
  -b "$JAR" "$BASE/users/me"
echo "Expired row is removed by the server:"; q "SELECT count(*) AS sessions_left FROM sessions"
echo "------------------------------------------------------------------------"

t S10 "Log in again for the idle test" 200 \
  "curl -c jar -X POST $BASE/sessions -H '$JSON' -d '{\"email\":\"$EMAIL\",\"password\":\"***\"}'" \
  -c "$JAR" -X POST "$BASE/sessions" -H "$JSON" -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}"
IDLE="$(date -u -d '-45 minutes' '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -u -v-45M '+%Y-%m-%d %H:%M:%S')"
echo "Simulate 45 minutes of inactivity (idle timeout is 30): set last_seen_at to $IDLE"
q "UPDATE sessions SET last_seen_at = '$IDLE'"
t S11 "Request after the idle timeout" 401 \
  "curl -b jar $BASE/users/me" \
  -b "$JAR" "$BASE/users/me"

t S12 "Login attempt from a foreign Origin (CSRF defence in depth)" 403 \
  "curl -X POST $BASE/sessions -H 'Origin: https://evil.example' -H '$JSON' -d '{...}'" \
  -X POST "$BASE/sessions" -H 'Origin: https://evil.example' -H "$JSON" -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}"

echo "Password storage (never plain text):"
q "SELECT email, substr(password_hash,1,7) AS hash_prefix, length(password_hash) AS hash_length FROM users WHERE email = '$EMAIL'"
echo "------------------------------------------------------------------------"
summary
