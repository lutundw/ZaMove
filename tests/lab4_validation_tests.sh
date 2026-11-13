#!/usr/bin/env bash
# =============================================================================
# Lab 4, Tasks 2 and 4.1 - schema validation and API behaviour against the contract.
# Every request goes straight to the API with curl (no browser, no client-side checks).
#
# Usage:  CURL_OPTS="--cacert /path/to/rootCA.pem" bash tests/lab4_validation_tests.sh https://localhost/zamove/api/v1
# Run on a fresh minute: the rate limiter counts requests (register 20/min, login 5/min).
# =============================================================================
BASE="${1:-https://localhost/api/v1}"
source "$(dirname "$0")/lib.sh"
JAR="$(mktemp)"; trap 'rm -f "$JAR"' EXIT
RUN="$(date +%s)"                                   # makes the e-mail unique on every run

echo "Target: $BASE"
echo "Started: $(date '+%Y-%m-%d %H:%M:%S %z')"
echo "========================================================================"

P1="{\"role\":\"customer\",\"full_name\":\"Mary Banda\",\"email\":\"mary.banda.$RUN@example.com\",\"phone\":\"0971234567\",\"password\":\"Passw0rdX\"}"
t 01 "Valid payload is accepted (control)" 201 \
  "curl -X POST $BASE/users -H '$JSON' -d '$P1'" \
  -X POST "$BASE/users" -H "$JSON" -d "$P1"

t 02 "Empty JSON object - every required property missing" 422 \
  "curl -X POST $BASE/users -H '$JSON' -d '{}'" \
  -X POST "$BASE/users" -H "$JSON" -d '{}'

P3='{"role":"customer","full_name":"A","email":"not-an-email","phone":"12345","password":"short"}'
t 03 "Invalid email, phone, name length and weak password" 422 \
  "curl -X POST $BASE/users -H '$JSON' -d '$P3'" \
  -X POST "$BASE/users" -H "$JSON" -d "$P3"

P4='{"role":"admin","full_name":"Eve Hacker","email":"eve@example.com","phone":"0971234567","password":"Passw0rdX","account_status":"active","is_admin":true}'
t 04 "additionalProperties:false - unknown fields and role 'admin' (privilege escalation)" 422 \
  "curl -X POST $BASE/users -H '$JSON' -d '$P4'" \
  -X POST "$BASE/users" -H "$JSON" -d "$P4"

P5='{"role":"customer","full_name":12345,"email":["a@b.com"],"phone":true,"password":null}'
t 05 "Wrong JSON types (number, array, boolean, null)" 422 \
  "curl -X POST $BASE/users -H '$JSON' -d '$P5'" \
  -X POST "$BASE/users" -H "$JSON" -d "$P5"

P6='{"role":"customer","full_name":"<script>alert(1)</script>","email":"xss@example.com","phone":"0971234567","password":"Passw0rdX"}'
t 06 "XSS attempt in full_name" 422 \
  "curl -X POST $BASE/users -H '$JSON' -d '$P6'" \
  -X POST "$BASE/users" -H "$JSON" -d "$P6"

t 07 "Malformed JSON (unclosed brace)" 400 \
  "curl -X POST $BASE/users -H '$JSON' -d '{\"role\":\"customer\",'" \
  -X POST "$BASE/users" -H "$JSON" -d '{"role":"customer",'

t 08 "Body is a JSON array, not an object" 400 \
  "curl -X POST $BASE/users -H '$JSON' -d '[1,2,3]'" \
  -X POST "$BASE/users" -H "$JSON" -d '[1,2,3]'

t 09 "Empty body" 400 \
  "curl -X POST $BASE/users -H '$JSON' --data-binary ''" \
  -X POST "$BASE/users" -H "$JSON" --data-binary ''

t 10 "Wrong Content-Type (text/plain)" 415 \
  "curl -X POST $BASE/users -H 'Content-Type: text/plain' -d '$P1'" \
  -X POST "$BASE/users" -H 'Content-Type: text/plain' -d "$P1"

BIG="$(head -c 11000 /dev/zero | tr '\0' 'a')"
t 11 "Body over 10 KB" 413 \
  "curl -X POST $BASE/users -H '$JSON' -d '{\"role\":\"customer\",\"full_name\":\"<11000 x a>\"}'" \
  -X POST "$BASE/users" -H "$JSON" -d "{\"role\":\"customer\",\"full_name\":\"$BIG\"}"

t 12 "Duplicate e-mail (database UNIQUE -> 409)" 409 \
  "curl -X POST $BASE/users -H '$JSON' -d '$P1'" \
  -X POST "$BASE/users" -H "$JSON" -d "$P1"

# --- authenticated endpoint with query parameters ----------------------------------
t 13 "Log in (sets the session cookie)" 200 \
  "curl -c jar -X POST $BASE/sessions -H '$JSON' -d '{\"email\":\"mary.banda.$RUN@example.com\",\"password\":\"Passw0rdX\"}'" \
  -c "$JAR" -X POST "$BASE/sessions" -H "$JSON" -d "{\"email\":\"mary.banda.$RUN@example.com\",\"password\":\"Passw0rdX\"}"

t 14 "GET /bookings - unexpected query parameter is rejected" 400 \
  "curl -b jar '$BASE/bookings?admin=true'" \
  -b "$JAR" "$BASE/bookings?admin=true"

t 15 "GET /bookings - limit above the contract maximum (100)" 400 \
  "curl -b jar '$BASE/bookings?limit=500'" \
  -b "$JAR" "$BASE/bookings?limit=500"

t 16 "GET /bookings - SQL injection in a query parameter" 400 \
  "curl -b jar \"$BASE/bookings?status=pending'%20OR%20'1'='1\"" \
  -b "$JAR" "$BASE/bookings?status=pending'%20OR%20'1'='1"

t 17 "GET /bookings - valid request" 200 \
  "curl -b jar '$BASE/bookings?status=pending&limit=10'" \
  -b "$JAR" "$BASE/bookings?status=pending&limit=10"

t 18 "Unknown path" 404 \
  "curl $BASE/orders" \
  "$BASE/orders"

t 19 "Method not described in the contract (PUT /users)" 405 \
  "curl -X PUT $BASE/users -H '$JSON' -d '{}'" \
  -X PUT "$BASE/users" -H "$JSON" -d '{}'

summary
