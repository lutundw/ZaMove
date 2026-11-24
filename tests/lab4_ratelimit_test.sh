#!/usr/bin/env bash
# =============================================================================
# Lab 4, Task 4.2 - rate limiting. POST /sessions is limited to 5 requests per
# 60 seconds per client (x-rate-limit in api/openapi/openapi.json).
# Eight wrong-password attempts: expect 401 x5, then 429 with Retry-After.
#
# Usage:  CURL_OPTS="--cacert rootCA.pem" bash tests/lab4_ratelimit_test.sh https://localhost/zamove/api/v1
# Start from a fresh window (wait 60 s after the other scripts, or empty the rate_limits table).
# =============================================================================
BASE="${1:-https://localhost/api/v1}"
source "$(dirname "$0")/lib.sh"
echo "Target: $BASE/sessions   (limit: 5 per 60 s)"
echo "Started: $(date '+%Y-%m-%d %H:%M:%S %z')"
echo "========================================================================"

B='{"email":"nobody@example.com","password":"WrongPass1"}'
codes=""
for i in 1 2 3 4 5 6 7 8; do
  out="$(curl -s -i "${COPTS[@]}" -X POST "$BASE/sessions" -H "$JSON" -d "$B")"
  code="$(printf '%s' "$out" | head -1 | awk '{print $2}')"
  rl="$(printf '%s' "$out" | tr -d '\r' | grep -i -E '^(ratelimit-limit|ratelimit-remaining|ratelimit-reset|retry-after):' | tr '\n' ' ')"
  printf 'attempt %s -> HTTP %s   %s\n' "$i" "$code" "$rl"
  codes="$codes $code"
done
echo "------------------------------------------------------------------------"

t 09 "The 9th attempt: full 429 response" 429 \
  "curl -i -X POST $BASE/sessions -H '$JSON' -d '$B'" \
  -X POST "$BASE/sessions" -H "$JSON" -d "$B"

[ "$codes" = " 401 401 401 401 401 429 429 429" ]; expect "attempts 1-5 answered 401, attempts 6-8 answered 429" $?
printf '%s' "$LAST_HEADERS" | grep -qi '^retry-after:'; expect "429 carries a Retry-After header" $?
printf '%s' "$LAST_HEADERS" | grep -qi '^ratelimit-remaining: 0'; expect "RateLimit-Remaining is 0" $?
summary
