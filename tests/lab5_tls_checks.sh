#!/usr/bin/env bash
# =============================================================================
# Lab 5, Tasks 1 and 4.2 - TLS 1.3, certificate, HSTS and HTTP -> HTTPS upgrade.
#
# Usage:  bash tests/lab5_tls_checks.sh https://localhost/zamove/api/v1 http://localhost/zamove/api/v1 /path/to/rootCA.pem
#   $1 HTTPS base URL   $2 plain-HTTP base URL   $3 mkcert root CA (mkcert -CAROOT)\rootCA.pem
# =============================================================================
HTTPS="${1:-https://localhost/api/v1}"; HTTP="${2:-http://localhost/api/v1}"; CA="${3:-}"
HOSTPORT="$(printf '%s' "$HTTPS" | sed -E 's#^https://([^/]+).*#\1#')"
HOST="${HOSTPORT%%:*}"; PORT="${HOSTPORT##*:}"; [ "$PORT" = "$HOSTPORT" ] && PORT=443
CACERT=(); [ -n "$CA" ] && CACERT=(--cacert "$CA")
pass=0; fail=0
ok() { if [ "$2" = 0 ]; then echo "RESULT: PASS ($1)"; pass=$((pass+1)); else echo "RESULT: FAIL ($1)"; fail=$((fail+1)); fi; echo "------------------------------------------------------------------------"; }

echo "Target: $HTTPS"; echo "Started: $(date '+%Y-%m-%d %H:%M:%S %z')"
echo "========================================================================"

echo "### CHECK 1 - TLS handshake negotiated with TLS 1.3 only"
echo "\$ curl -v --tlsv1.3 ${CA:+--cacert rootCA.pem }$HTTPS/users/me"
OUT="$(curl -sv "${CACERT[@]}" --tlsv1.3 "$HTTPS/users/me" -o /dev/null 2>&1)"
printf '%s\n' "$OUT" | grep -E '^\* (SSL connection|ALPN|Server certificate|  (subject|start date|expire date|issuer)|SSL certificate verify)'
printf '%s' "$OUT" | grep -q 'SSL connection using TLSv1.3'; ok "connection uses TLSv1.3" $?
printf '%s' "$OUT" | grep -q 'SSL certificate verify ok'; ok "certificate chain verifies against the mkcert CA" $?

echo "### CHECK 2 - Certificate presented by the server"
echo "\$ openssl s_client -connect $HOST:$PORT -tls1_3 -servername $HOST | openssl x509 -noout -subject -issuer -dates -ext subjectAltName"
openssl s_client -connect "$HOST:$PORT" -tls1_3 -servername "$HOST" ${CA:+-CAfile "$CA"} </dev/null 2>/dev/null | tee /tmp/zm_sclient.$$ | grep -E 'Protocol|Cipher is|Verification|Verify return' | head -4
openssl x509 -in /tmp/zm_sclient.$$ -noout -subject -issuer -dates -ext subjectAltName 2>/dev/null; rm -f /tmp/zm_sclient.$$
echo "------------------------------------------------------------------------"

echo "### CHECK 3 - A client limited to TLS 1.2 must be refused"
echo "\$ curl -v --tls-max 1.2 $HTTPS/users/me"
OUT="$(curl -sv "${CACERT[@]}" --tls-max 1.2 "$HTTPS/users/me" -o /dev/null 2>&1)"; RC=$?
printf '%s\n' "$OUT" | grep -E 'alert|error:|SSL_connect|handshake' | head -3
echo "curl exit code: $RC"
[ $RC -ne 0 ]; ok "TLS 1.2 handshake is rejected" $?

echo "### CHECK 4 - HSTS header on an HTTPS response"
echo "\$ curl -sI $HTTPS/users/me"
HDR="$(curl -sI "${CACERT[@]}" "$HTTPS/users/me" | tr -d '\r')"
printf '%s\n' "$HDR" | grep -i -E '^(HTTP/|strict-transport-security|x-content-type-options|cache-control|content-security-policy)'
printf '%s' "$HDR" | grep -qi '^strict-transport-security: max-age=31536000'; ok "Strict-Transport-Security: max-age >= 1 year" $?

echo "### CHECK 5 - Plain HTTP is upgraded to HTTPS"
echo "\$ curl -si $HTTP/users/me"
HDR="$(curl -si "$HTTP/users/me" | tr -d '\r' | sed '/^$/q')"
printf '%s\n' "$HDR" | grep -i -E '^(HTTP/|location)'
printf '%s' "$HDR" | head -1 | grep -q ' 30[18] '; ok "HTTP request answered with a 301/308 redirect" $?
printf '%s' "$HDR" | grep -qi '^location: https://'; ok "redirect target is an https:// URL" $?

echo "SUMMARY: $pass passed, $fail failed"; [ "$fail" = 0 ]
