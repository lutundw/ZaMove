#!/usr/bin/env bash
# LAB 9: HTTP header / cache / compression checks on a machine WITHOUT XAMPP (PHP built-in server + SQLite + static_mirror.php).
#   bash tests/run_local_lab9.sh        (log goes to evidence/lab9/lab9_http_tests.txt)
cd "$(dirname "$0")/.."
OUT=evidence/lab9; mkdir -p $OUT; LOG=$OUT/lab9_http_tests.txt; : > $LOG
DB=/tmp/zm_l9_$$.db; PORT=8141; JAR=$(mktemp)
export ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE="$DB" ZAMOVE_ENV=test ZAMOVE_RL_OFF=1 ZAMOVE_ALLOW_HTTP=1 ZAMOVE_STATIC_MIRROR=1 ZAMOVE_APP_KEY="$(printf 'k%.0s' {1..40})"
rm -f "$DB"; php tools/migrate.php up >/dev/null
php -r 'require "api/lib/db.php"; $p=db();
 $p->exec("INSERT INTO users (full_name,email,phone,password_hash,role) VALUES (\"Op\",\"op@example.com\",\"+260971000001\",\"x\",\"operator\")");
 $p->prepare("INSERT INTO users (full_name,email,phone,password_hash,role) VALUES (\"Cust\",\"cust@example.com\",\"+260971000002\",?,\"customer\")")->execute([password_hash("Str0ng!Passw0rd",PASSWORD_DEFAULT)]);
 $p->exec("INSERT INTO operators (user_id,business_name,business_type,business_address,verification_status) VALUES (1,\"T\",\"bus\",\"Lusaka\",\"approved\")");
 $p->exec("INSERT INTO vehicles (operator_id,registration_number,vehicle_type,seat_capacity) VALUES (1,\"BAA1\",\"bus\",40)");
 $p->exec("INSERT INTO routes (operator_id,origin,destination,base_fare) VALUES (1,\"Lusaka\",\"Kitwe\",260)");
 $d=gmdate("Y-m-d H:i:s",time()+7*86400);
 $p->exec("INSERT INTO schedules (route_id,vehicle_id,departure_at,fare_per_seat,total_seats,seats_available) VALUES (1,1,\"$d\",260,3,3)");'
php -S 127.0.0.1:$PORT -t . tests/router.php >/dev/null 2>&1 & SRV=$!; trap 'kill $SRV 2>/dev/null; rm -f "$DB" "$JAR"' EXIT; sleep 1
U="http://127.0.0.1:$PORT"; pass=0; fail=0
say(){ echo "$@" | tee -a $LOG; }
hdr(){ curl -s -D - -o /dev/null "$@" | tr -d '\r'; }
chk(){ if echo "$3" | grep -qiE "$2"; then say "PASS $1"; pass=$((pass+1)); else say "FAIL $1   (wanted /$2/)"; fail=$((fail+1)); fi; }
JS=$(ls dist/assets/app.*.js | grep -v '\.br\|\.gz' | head -1); JSU="$U/$JS"; IMG=$(ls dist/assets/img/hero-960.*.avif | head -1)
say "\$ curl -sI $JSU -H 'Accept-Encoding: br'"; H=$(hdr -I -H 'Accept-Encoding: br, gzip' "$JSU"); say "$H"
chk "hashed JS: Cache-Control public, max-age=31536000, immutable" 'cache-control: public, max-age=31536000, immutable' "$H"
chk "hashed JS: Content-Encoding br (pre-compressed)" 'content-encoding: br' "$H"
H=$(hdr -I -H 'Accept-Encoding: gzip' "$JSU"); chk "hashed JS: gzip fallback when the browser has no br" 'content-encoding: gzip' "$H"
H=$(hdr -I "$JSU"); chk "hashed JS: identity when no Accept-Encoding" '^content-length' "$H"; ! echo "$H" | grep -qi content-encoding && { say "PASS no Content-Encoding without Accept-Encoding"; pass=$((pass+1)); } || { say "FAIL identity"; fail=$((fail+1)); }
say ""; say "\$ curl -sI $U/img/hero.png   (un-hashed source image) twice, second time with If-None-Match"
H=$(hdr -I "$U/img/hero.png"); say "$H"; ET=$(echo "$H" | grep -i '^etag' | cut -d' ' -f2-)
chk "un-hashed image: ETag present" '^etag' "$H"; chk "un-hashed image: Cache-Control public, max-age=86400" 'max-age=86400' "$H"
H=$(hdr -I -H "If-None-Match: $ET" "$U/img/hero.png"); say "$H"; chk "If-None-Match -> 304 Not Modified" '^HTTP/1.1 304' "$H"
say ""; say "\$ curl -s -r 0-1023 -D - -o /dev/null $U/img/hero.png"; H=$(curl -s -r 0-1023 -D - -o /dev/null "$U/img/hero.png" | tr -d '\r'); say "$H"
chk "byte range -> 206 Partial Content with Content-Range" '^HTTP/1.1 206' "$H"; chk "Accept-Ranges: bytes" 'accept-ranges: bytes' "$H"
say ""; say "\$ curl -si '$U/api/v1/schedules?origin=Lusaka&destination=Kitwe' -H 'Accept-Encoding: gzip'  (public API)"
H=$(hdr -H 'Accept-Encoding: gzip' "$U/api/v1/schedules?origin=Lusaka&destination=Kitwe"); say "$H"; ET=$(echo "$H" | grep -i '^etag' | cut -d' ' -f2-)
chk "public API: Cache-Control public, max-age=60, stale-while-revalidate=300" 'cache-control: public, max-age=60, stale-while-revalidate=300' "$H"
chk "public API: strong ETag" '^etag: "' "$H"
H=$(hdr -H "If-None-Match: $ET" "$U/api/v1/schedules?origin=Lusaka&destination=Kitwe"); say "\$ ... -H 'If-None-Match: $ET'"; say "$H"; chk "public API: matching If-None-Match -> 304" '^HTTP/1.1 304' "$H"
RAW=$(curl -s "$U/api/v1/schedules?origin=Lusaka&destination=Kitwe" | wc -c); GZ=$(curl -s -H 'Accept-Encoding: gzip' "$U/api/v1/schedules?origin=Lusaka&destination=Kitwe" | wc -c); say "API body: $RAW bytes plain, $GZ bytes gzip"
curl -s -c $JAR -o /dev/null -X POST "$U/api/v1/sessions" -H 'Content-Type: application/json' -d '{"email":"cust@example.com","password":"Str0ng!Passw0rd"}'
say ""; say "\$ curl -sI -b cookie $U/api/v1/users/me   and   /bookings   (authenticated routes)"
H=$(hdr -b $JAR "$U/api/v1/users/me"); say "$H"; chk "authenticated /users/me: Cache-Control private, no-store" 'cache-control: private, no-store' "$H"
H=$(hdr -b $JAR "$U/api/v1/bookings"); chk "authenticated /bookings: Cache-Control private, no-store" 'cache-control: private, no-store' "$H"
H=$(hdr -X POST "$U/api/v1/sessions" -H 'Content-Type: application/json' -d '{}'); chk "write route (POST /sessions): never cacheable" 'cache-control: private, no-store' "$H"
say ""; say "\$ curl -sD - -o /dev/null $U/index.html -H 'Accept-Encoding: gzip'  (HTML from page.php)"
H=$(hdr -H 'Accept-Encoding: gzip' "$U/index.html"); say "$H" | head -20; chk "HTML: Content-Encoding gzip" 'content-encoding: gzip' "$H"; chk "HTML: Cache-Control private, no-cache (nonce changes per response)" 'cache-control: private, no-cache' "$H"
chk "HTML: CSP still has a nonce and no unsafe-inline" "script-src 'nonce-" "$H"; ! echo "$H" | grep -qi unsafe-inline && { say "PASS CSP has no 'unsafe-inline'"; pass=$((pass+1)); } || { say "FAIL unsafe-inline"; fail=$((fail+1)); }
B=$(curl -s -H 'Accept-Encoding: gzip' "$U/index.html" | gunzip | grep -c 'dist/assets/app\.'); [ "$B" -ge 1 ] && { say "PASS index.html references the content-hashed bundle (dist/assets/app.<hash>.js)"; pass=$((pass+1)); } || { say "FAIL hashed bundle reference"; fail=$((fail+1)); }
chk "dist/index.html cannot be fetched directly (no nonce)" '^HTTP/1.1 403' "$(hdr -I "$U/dist/index.html")"
say ""; say "SUMMARY: $pass passed, $fail failed"; say "RESULT: $([ $fail = 0 ] && echo PASS || echo FAIL)"; [ $fail = 0 ]
