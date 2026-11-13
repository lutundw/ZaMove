# Shared helpers for the Lab 4 / Lab 5 test scripts (source this file).
#   BASE        API base, e.g. https://localhost/zamove/api/v1
#   CURL_OPTS   extra curl options, e.g. "--cacert /path/to/rootCA.pem"   (mkcert CA)
read -ra COPTS <<< "${CURL_OPTS:-}"
JSON='Content-Type: application/json'
pass=0; fail=0

# t <no> <title> <expected-status> <command shown in the log> <curl args...>
# A response with status >= 400 must also be application/problem+json (RFC 7807).
# SHOW_HEADERS=1 prints the whole header block (used for the cookie / HSTS evidence).
t() {
  local no="$1" title="$2" want="$3" shown="$4"; shift 4
  local resp status ctype body headers code ok=1
  resp="$(curl -s -i "${COPTS[@]}" "$@")"
  status="$(printf '%s' "$resp" | head -1 | tr -d '\r')"
  headers="$(printf '%s' "$resp" | sed '/^\r\{0,1\}$/q' | tr -d '\r' | sed '1d;/^$/d')"
  ctype="$(printf '%s\n' "$headers" | grep -i '^content-type:' | head -1)"
  body="$(printf '%s' "$resp" | sed '1,/^\r\{0,1\}$/d')"
  code="$(printf '%s' "$status" | awk '{print $2}')"
  LAST_CODE="$code"; LAST_HEADERS="$headers"; LAST_BODY="$body"
  echo "### TEST $no - $title"
  echo "\$ $shown"
  echo "$status"
  if [ "${SHOW_HEADERS:-0}" = "1" ]; then
    printf '%s\n' "$headers" | grep -v -i -E '^(date|host|connection|x-powered-by):'
  else
    [ -n "$ctype" ] && echo "$ctype"
    printf '%s\n' "$headers" | grep -i -E '^(ratelimit-|retry-after|allow:|www-authenticate)'
  fi
  echo
  [ -n "$body" ] && echo "$body"
  [ "$code" = "$want" ] || ok=0
  if [ "$want" -ge 400 ] 2>/dev/null && ! printf '%s' "$ctype" | grep -qi 'application/problem+json'; then ok=0; fi
  if [ $ok = 1 ]; then echo "RESULT: PASS (expected $want, got $code)"; pass=$((pass+1));
  else echo "RESULT: FAIL (expected $want, got $code; $ctype)"; fail=$((fail+1)); fi
  echo "------------------------------------------------------------------------"
}

# expect <title> <condition-result 0|1> : a plain pass/fail line for non-HTTP checks
expect() {
  if [ "$2" = "0" ]; then echo "RESULT: PASS ($1)"; pass=$((pass+1)); else echo "RESULT: FAIL ($1)"; fail=$((fail+1)); fi
  echo "------------------------------------------------------------------------"
}

summary() { echo "SUMMARY: $pass passed, $fail failed"; [ "$fail" = 0 ]; }
