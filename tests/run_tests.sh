#!/usr/bin/env bash
# =============================================================================
# Defense-in-depth bypass tests for POST /api/auth/register.php
#
# These requests go straight to the server with curl, so the browser (and its
# client-side validation) is completely bypassed - the same as disabling
# JavaScript or sending the request from Postman.
#
# Usage (XAMPP):   bash tests/run_tests.sh http://localhost/zamove/api/auth/register.php
# Usage (default): bash tests/run_tests.sh            (http://127.0.0.1:8099/...)
#
# Optional: set ZAMOVE_SQLITE=/path/to/test.db to also print the rows that were
# actually stored (only used with the SQLite test database, see run_local_tests.sh).
# =============================================================================
URL="${1:-http://127.0.0.1:8099/api/auth/register.php}"
JSON='Content-Type: application/json'
pass=0; fail=0

# t <no> <title> <expected-status> <command shown in the log> <curl args...>
t() {
  local no="$1" title="$2" want="$3" shown="$4"; shift 4
  local resp status ctype body
  resp="$(curl -s -i "$@")"
  status="$(printf '%s' "$resp" | head -1 | tr -d '\r')"
  ctype="$(printf '%s' "$resp" | grep -i '^content-type:' | head -1 | tr -d '\r')"
  body="$(printf '%s' "$resp" | sed '1,/^\r\{0,1\}$/d')"
  local code; code="$(printf '%s' "$status" | awk '{print $2}')"
  echo "### TEST $no - $title"
  echo "\$ $shown"
  echo "$status"
  [ -n "$ctype" ] && echo "$ctype"
  echo
  echo "$body"
  if [ "$code" = "$want" ]; then echo "RESULT: PASS (expected $want, got $code)"; pass=$((pass+1));
  else echo "RESULT: FAIL (expected $want, got $code)"; fail=$((fail+1)); fi
  echo "------------------------------------------------------------------------"
}

post() { # post <json>   -> curl args for a normal JSON POST
  printf '%s\n' -X POST "$URL" -H "$JSON" -d "$1"
}

echo "Target: $URL"
echo "Started: $(date '+%Y-%m-%d %H:%M:%S %z')"
echo "========================================================================"

# --- 1. control: a valid request is accepted ---------------------------------
P1='{"role":"customer","full_name":"Mary Banda","email":"mary.banda@example.com","phone":"0971234567","password":"Passw0rdX"}'
t 01 "Valid payload is accepted (control)" 201 \
  "curl -X POST $URL -H '$JSON' -d '$P1'" \
  -X POST "$URL" -H "$JSON" -d "$P1"

# --- 2. missing / empty fields -> 422 ------------------------------------------
t 02 "Empty JSON object - every required field missing" 422 \
  "curl -X POST $URL -H '$JSON' -d '{}'" \
  -X POST "$URL" -H "$JSON" -d '{}'

# --- 3. several invalid values at once -> 422 ----------------------------------
P3='{"role":"customer","full_name":"A","email":"not-an-email","phone":"12345","password":"short"}'
t 03 "Invalid email, phone, name length and weak password" 422 \
  "curl -X POST $URL -H '$JSON' -d '$P3'" \
  -X POST "$URL" -H "$JSON" -d "$P3"

# --- 4. XSS payload -> 422 -----------------------------------------------------
P4='{"role":"customer","full_name":"<script>alert(1)</script>","email":"xss@example.com","phone":"0971234567","password":"Passw0rdX"}'
t 04 "XSS attempt in full_name" 422 \
  "curl -X POST $URL -H '$JSON' -d '$P4'" \
  -X POST "$URL" -H "$JSON" -d "$P4"

# --- 5. harmless markup is SANITISED (stripped), not stored -------------------
P5='{"role":"customer","full_name":"<b>Jane</b> Phiri","email":"jane.phiri@example.com","phone":"+260 96 555 1234","password":"Passw0rdX"}'
t 05 "Markup is stripped and the phone number normalised (sanitisation)" 201 \
  "curl -X POST $URL -H '$JSON' -d '$P5'" \
  -X POST "$URL" -H "$JSON" -d "$P5"

# --- 6. SQL injection attempts -> 422 ------------------------------------------
P6='{"role":"customer","full_name":"Robert'"'"'); DROP TABLE users;--","email":"'"'"' OR '"'"'1'"'"'='"'"'1","phone":"0971234567","password":"Passw0rdX"}'
t 06 "SQL injection strings in full_name and email" 422 \
  "curl -X POST $URL -H '$JSON' -d '$P6'" \
  -X POST "$URL" -H "$JSON" -d "$P6"

# --- 7. privilege escalation -> 422 ---------------------------------------------
P7='{"role":"admin","full_name":"Evil Admin","email":"evil@example.com","phone":"0971234567","password":"Passw0rdX","account_status":"active","is_admin":true}'
t 07 "Privilege escalation: role=admin plus extra fields" 422 \
  "curl -X POST $URL -H '$JSON' -d '$P7'" \
  -X POST "$URL" -H "$JSON" -d "$P7"

# --- 8. wrong data types -> 422 -------------------------------------------------
P8='{"role":"customer","full_name":{"first":"Mary"},"email":["a@b.com"],"phone":260971234567,"password":12345678}'
t 08 "Wrong JSON types (object, array, numbers)" 422 \
  "curl -X POST $URL -H '$JSON' -d '$P8'" \
  -X POST "$URL" -H "$JSON" -d "$P8"

# --- 9. password longer than bcrypt's 72-byte limit -> 422 ------------------------
LONGPW="$(head -c 80 /dev/zero | tr '\0' 'a')Aa1"
P9="{\"role\":\"customer\",\"full_name\":\"Long Pass\",\"email\":\"long@example.com\",\"phone\":\"0971234567\",\"password\":\"$LONGPW\"}"
t 09 "83-byte password (over the 72-byte bcrypt limit)" 422 \
  "curl -X POST $URL -H '$JSON' -d '{... \"password\":\"<83 characters>\"}'" \
  -X POST "$URL" -H "$JSON" -d "$P9"

# --- 10. malformed / empty / wrong-shape bodies -> 400 ----------------------------
t 10 "Malformed JSON" 400 \
  "curl -X POST $URL -H '$JSON' -d '{\"email\": \"a@b.com\",'" \
  -X POST "$URL" -H "$JSON" -d '{"email": "a@b.com",'

t 11 "Empty request body" 400 \
  "curl -X POST $URL -H '$JSON'" \
  -X POST "$URL" -H "$JSON"

t 12 "JSON array instead of an object" 400 \
  "curl -X POST $URL -H '$JSON' -d '[1,2,3]'" \
  -X POST "$URL" -H "$JSON" -d '[1,2,3]'

# --- 13. wrong content type / method / size -------------------------------------
t 13 "Form-encoded body instead of JSON" 415 \
  "curl -X POST $URL -d 'email=a@b.com&password=x'" \
  -X POST "$URL" -d 'email=a@b.com&password=x'

t 14 "GET instead of POST" 405 \
  "curl -X GET $URL" \
  -X GET "$URL"

BIG="$(head -c 12000 /dev/zero | tr '\0' 'a')"
t 15 "Oversized body (12,000-character name)" 413 \
  "curl -X POST $URL -H '$JSON' -d '{\"full_name\":\"<12000 characters>\", ...}'" \
  -X POST "$URL" -H "$JSON" -d "{\"role\":\"customer\",\"full_name\":\"$BIG\",\"email\":\"big@example.com\",\"phone\":\"0971234567\",\"password\":\"Passw0rdX\"}"

# --- 16. database error mapped to a clean HTTP error -----------------------------
t 16 "Duplicate email (UNIQUE constraint -> 409, no SQL leaked)" 409 \
  "curl -X POST $URL -H '$JSON' -d '$P1'" \
  -X POST "$URL" -H "$JSON" -d "$P1"

echo
echo "SUMMARY: $pass passed, $fail failed"

# --- proof that rejected payloads never reached the database ---------------------
if [ -n "${ZAMOVE_SQLITE:-}" ]; then
  echo
  echo "Rows stored in the users table (only tests 01 and 05 should appear):"
  php -r '
    $p = new PDO("sqlite:" . getenv("ZAMOVE_SQLITE"));
    $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    foreach ($p->query("SELECT user_id, full_name, email, phone, role, account_status, substr(password_hash,1,7) AS hash_prefix, length(password_hash) AS hash_len FROM users") as $r) {
      echo json_encode($r, JSON_UNESCAPED_SLASHES), "\n";
    }'
fi
[ "$fail" -eq 0 ]
