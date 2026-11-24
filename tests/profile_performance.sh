#!/usr/bin/env bash
# =============================================================================
# DevOps profiling (Lab 4 Task 4: API latency; Lab 5: TLS 1.3 handshake cost).
#
# Usage:  CURL_OPTS="--cacert rootCA.pem" bash tests/profile_performance.sh https://localhost/zamove/api/v1 [samples]
# PRE_REQUEST (optional) is a command run before every request; the local runner uses it to
# empty the rate-limit table so the 429 limiter never distorts the timings.
# =============================================================================
BASE="${1:-https://localhost/api/v1}"; N="${2:-30}"
read -ra COPTS <<< "${CURL_OPTS:-}"
JSON='Content-Type: application/json'
pre() { [ -n "${PRE_REQUEST:-}" ] && eval "$PRE_REQUEST" >/dev/null 2>&1; }

# stats <file of milliseconds>  ->  mean median p95 max
stats() { sort -n "$1" | awk '{a[NR]=$1; s+=$1} END {
  i=int(NR*0.95+0.999); if (i<1) i=1; m=(NR%2)?a[(NR+1)/2]:(a[NR/2]+a[NR/2+1])/2;
  printf "%7.1f %7.1f %7.1f %7.1f", s/NR, m, a[i], a[NR] }'; }

echo "Target: $BASE   samples per row: $N"
echo "Started: $(date '+%Y-%m-%d %H:%M:%S %z')"
echo "========================================================================"

echo "### TABLE A - TLS 1.3 handshake (a brand-new connection for every sample, milliseconds)"
: > /tmp/zm_tcp.$$; : > /tmp/zm_tls.$$; : > /tmp/zm_tot.$$
for _ in $(seq "$N"); do
  pre
  read -r c a t < <(curl -s -o /dev/null "${COPTS[@]}" --tlsv1.3 -w '%{time_connect} %{time_appconnect} %{time_total}\n' "$BASE/users/me")
  awk -v c="$c" 'BEGIN{printf "%.3f\n", c*1000}' >> /tmp/zm_tcp.$$
  awk -v c="$c" -v a="$a" 'BEGIN{printf "%.3f\n", (a-c)*1000}' >> /tmp/zm_tls.$$
  awk -v t="$t" 'BEGIN{printf "%.3f\n", t*1000}' >> /tmp/zm_tot.$$
done
printf '%-44s %7s %7s %7s %7s\n' "Phase" "mean" "median" "p95" "max"
printf '%-44s %s\n' "TCP connect"                          "$(stats /tmp/zm_tcp.$$)"
printf '%-44s %s\n' "TLS 1.3 handshake (after TCP connect)" "$(stats /tmp/zm_tls.$$)"
printf '%-44s %s\n' "Whole request incl. handshake (401 reply)" "$(stats /tmp/zm_tot.$$)"
rm -f /tmp/zm_tcp.$$ /tmp/zm_tls.$$ /tmp/zm_tot.$$
echo "------------------------------------------------------------------------"

echo "### TABLE B - API response time by request type (milliseconds, whole request over HTTPS)"
RUN="$(date +%s)"
pre
curl -s "${COPTS[@]}" -X POST "$BASE/users" -H "$JSON" \
  -d "{\"role\":\"customer\",\"full_name\":\"Perf Tester\",\"email\":\"perf.$RUN@example.com\",\"phone\":\"0971234567\",\"password\":\"Passw0rdX\"}" -o /dev/null
JAR="$(mktemp)"
timed() { # <label> <expected status> <curl args...>
  local label="$1" want="$2"; shift 2; : > /tmp/zm_t.$$; local bad=0
  for _ in $(seq "$N"); do
    pre
    read -r code t < <(curl -s -o /dev/null "${COPTS[@]}" -w '%{http_code} %{time_total}\n' "$@")
    [ "$code" = "$want" ] || bad=$((bad+1))
    awk -v t="$t" 'BEGIN{printf "%.3f\n", t*1000}' >> /tmp/zm_t.$$
  done
  printf '%-44s %s   (HTTP %s%s)\n' "$label" "$(stats /tmp/zm_t.$$)" "$want" "$([ $bad -gt 0 ] && echo ", $bad unexpected")"
  rm -f /tmp/zm_t.$$
}
printf '%-44s %7s %7s %7s %7s\n' "Request" "mean" "median" "p95" "max"
timed "GET  /users/me without cookie (rejected)" 401 "$BASE/users/me"
timed "POST /users with invalid body (schema)"    422 -X POST "$BASE/users" -H "$JSON" -d '{"role":"admin"}'
timed "POST /users with malformed JSON"           400 -X POST "$BASE/users" -H "$JSON" -d '{"role":'
timed "POST /sessions wrong password (bcrypt)"    401 -X POST "$BASE/sessions" -H "$JSON" -d "{\"email\":\"perf.$RUN@example.com\",\"password\":\"WrongPass1\"}"
timed "POST /sessions correct password (bcrypt)"  200 -X POST "$BASE/sessions" -H "$JSON" -d "{\"email\":\"perf.$RUN@example.com\",\"password\":\"Passw0rdX\"}"
curl -s -c "$JAR" "${COPTS[@]}" -X POST "$BASE/sessions" -H "$JSON" -d "{\"email\":\"perf.$RUN@example.com\",\"password\":\"Passw0rdX\"}" -o /dev/null
timed "GET  /bookings with a valid session"       200 -b "$JAR" "$BASE/bookings"
rm -f "$JAR"
echo "------------------------------------------------------------------------"
echo "SUMMARY: profiling finished"
