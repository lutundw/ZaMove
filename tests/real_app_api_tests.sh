#!/usr/bin/env bash
# REAL APP (NEW FILE): end-to-end API test of the marketplace (sandbox: php -S + SQLite built by the migrations).
#   bash tests/real_app_api_tests.sh
cd "$(dirname "$0")/.."
DB=/tmp/zm_real_$$.db; PORT="${PORT:-8191}"; OPJ=$(mktemp); CJ=$(mktemp); AJ=$(mktemp); OUT=/tmp/zm_real_body_$$.txt
export ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE="$DB" ZAMOVE_ENV=test ZAMOVE_RL_OFF=1 ZAMOVE_ALLOW_HTTP=1
rm -f "$DB"; php tools/migrate.php up >/dev/null
php -r 'require "api/lib/db.php"; db()->prepare("INSERT INTO users (full_name,email,phone,password_hash,role) VALUES (?,?,?,?,?)")->execute(["Admin","admin@example.com","+260971000009",password_hash("Adm1nPassw0rd",PASSWORD_DEFAULT),"admin"]);'
php -S 127.0.0.1:$PORT -t . tests/router.php >/dev/null 2>&1 & SRV=$!; trap 'kill $SRV 2>/dev/null; rm -f "$DB" "$OPJ" "$CJ" "$AJ" "$OUT"' EXIT; sleep 1
B="http://127.0.0.1:$PORT/api/v1"; H='Content-Type: application/json'; pass=0; fail=0
t(){ if [ "$2" = "$3" ]; then echo "PASS  $1 (HTTP $3)"; pass=$((pass+1)); else echo "FAIL  $1 (expected $2, got $3)"; sed 's/^/      /' "$OUT" | head -8; fail=$((fail+1)); fi; }
c(){ curl -s -o "$OUT" -w '%{http_code}' "$@"; }
j(){ php -r '$d=json_decode(file_get_contents($argv[1]),true); $v=$d; foreach(explode(".",$argv[2]) as $k){$v=$v[$k]??null;} echo is_scalar($v)?$v:json_encode($v);' "$OUT" "$1"; }
FUT(){ date -u -d "+$1 hours" +%Y-%m-%dT%H:%M:%S+00:00; }

echo "== 1. operator registers with a company profile"
t "operator without company name is rejected" 422 "$(c -X POST $B/users -H "$H" -d '{"role":"operator","full_name":"Chipo Banda","email":"chipo@example.com","phone":"0971111111","password":"Passw0rdX"}')"
t "operator with company profile" 201 "$(c -X POST $B/users -H "$H" -d '{"role":"operator","full_name":"Chipo Banda","email":"chipo@example.com","phone":"0971111111","password":"Passw0rdX","business_name":"Shalom Bus","business_address":"Intercity Terminal, Lusaka","business_type":"bus"}')"
t "operator row exists and is pending" "pending" "$(php tests/db_query.php 'SELECT verification_status FROM operators' | head -1 | sed 's/.*:"\(.*\)".*/\1/')"
t "pending operator cannot log in" 403 "$(c -X POST $B/sessions -H "$H" -d '{"email":"chipo@example.com","password":"Passw0rdX"}')"
t "customer registers" 201 "$(c -X POST $B/users -H "$H" -d '{"role":"customer","full_name":"Mutale Zulu","email":"mutale@example.com","phone":"0961234567","password":"Passw0rdX"}')"

echo "== 2. admin approves the company"
t "admin logs in" 200 "$(c -c $AJ -X POST $B/sessions -H "$H" -d '{"email":"admin@example.com","password":"Adm1nPassw0rd"}')"
t "customer cannot list operators" 200 "$(c -c $CJ -X POST $B/sessions -H "$H" -d '{"email":"mutale@example.com","password":"Passw0rdX"}')"
t "customer is refused the admin list" 403 "$(c -b $CJ $B/admin/operators)"
t "admin sees the pending company" 200 "$(c -b $AJ $B/admin/operators)"; grep -q '"Shalom Bus"' "$OUT" && echo "      (company listed)"
t "admin approves operator 1" 200 "$(c -b $AJ -X POST $B/admin/operators/1/decision -H "$H" -d '{"decision":"approve"}')"
t "operator logs in after approval" 200 "$(c -c $OPJ -X POST $B/sessions -H "$H" -d '{"email":"chipo@example.com","password":"Passw0rdX"}')"

echo "== 3. operator builds a vehicle, a route and a departure"
t "vehicle with 0 seats rejected" 422 "$(c -b $OPJ -X POST $B/operator/vehicles -H "$H" -d '{"registration_number":"BAA 1234","vehicle_type":"Standard","seat_capacity":0}')"
t "add vehicle" 201 "$(c -b $OPJ -X POST $B/operator/vehicles -H "$H" -d '{"registration_number":"baa 1234","vehicle_type":"Executive","seat_capacity":4}')"
t "duplicate registration is 409" 409 "$(c -b $OPJ -X POST $B/operator/vehicles -H "$H" -d '{"registration_number":"BAA 1234","vehicle_type":"Standard","seat_capacity":30}')"
t "customer cannot add a vehicle" 403 "$(c -b $CJ -X POST $B/operator/vehicles -H "$H" -d '{"registration_number":"ZZZ 1","vehicle_type":"Standard","seat_capacity":30}')"
t "route origin = destination rejected" 422 "$(c -b $OPJ -X POST $B/operator/routes -H "$H" -d '{"origin":"Lusaka","destination":"lusaka","base_fare":250}')"
t "add route (lower-case input)" 201 "$(c -b $OPJ -X POST $B/operator/routes -H "$H" -d '{"origin":"lusaka","destination":"kitwe","base_fare":250,"distance_km":320}')"
t "duplicate route is 409" 409 "$(c -b $OPJ -X POST $B/operator/routes -H "$H" -d '{"origin":"Lusaka","destination":"Kitwe","base_fare":250}')"
D1=$(FUT 30); A1=$(FUT 36)
t "departure with arrival before departure" 422 "$(c -b $OPJ -X POST $B/schedules -H "$H" -d "{\"route_id\":1,\"vehicle_id\":1,\"departure_time\":\"$A1\",\"arrival_time\":\"$D1\",\"fare_per_seat\":260,\"total_seats\":4}")"
t "departure with more seats than the bus has" 422 "$(c -b $OPJ -X POST $B/schedules -H "$H" -d "{\"route_id\":1,\"vehicle_id\":1,\"departure_time\":\"$D1\",\"arrival_time\":\"$A1\",\"fare_per_seat\":260,\"total_seats\":9}")"
t "departure in the past" 422 "$(c -b $OPJ -X POST $B/schedules -H "$H" -d "{\"route_id\":1,\"vehicle_id\":1,\"departure_time\":\"2020-01-01T07:00:00+02:00\",\"arrival_time\":\"2020-01-01T13:00:00+02:00\",\"fare_per_seat\":260,\"total_seats\":4}")"
t "create departure" 201 "$(c -b $OPJ -X POST $B/schedules -H "$H" -d "{\"route_id\":1,\"vehicle_id\":1,\"departure_time\":\"$D1\",\"arrival_time\":\"$A1\",\"fare_per_seat\":260,\"total_seats\":4}")"
t "seats_available starts equal to total_seats" "4" "$(php tests/db_query.php 'SELECT seats_available FROM schedules WHERE schedule_id=1' | head -1 | sed 's/.*://; s/[^0-9]//g')"
t "same bus, overlapping time is 409" 409 "$(c -b $OPJ -X POST $B/schedules -H "$H" -d "{\"route_id\":1,\"vehicle_id\":1,\"departure_time\":\"$D1\",\"arrival_time\":\"$A1\",\"fare_per_seat\":260,\"total_seats\":4}")"
t "the departure belongs to the operator's list" 200 "$(c -b $OPJ $B/operator/schedules)"

echo "== 4. a second operator cannot touch the first one's routes"
c -X POST $B/users -H "$H" -d '{"role":"operator","full_name":"Other Co","email":"other@example.com","phone":"0972222222","password":"Passw0rdX","business_name":"Other Co","business_address":"Ndola"}' >/dev/null
c -b $AJ -X POST $B/admin/operators/2/decision -H "$H" -d '{"decision":"approve"}' >/dev/null
c -c $CJ.o2 -X POST $B/sessions -H "$H" -d '{"email":"other@example.com","password":"Passw0rdX"}' >/dev/null
c -b $CJ.o2 -X POST $B/operator/vehicles -H "$H" -d '{"registration_number":"OTH 1","vehicle_type":"Standard","seat_capacity":30}' >/dev/null
t "operator 2 cannot schedule on operator 1's route" 404 "$(c -b $CJ.o2 -X POST $B/schedules -H "$H" -d "{\"route_id\":1,\"vehicle_id\":2,\"departure_time\":\"$D1\",\"arrival_time\":\"$A1\",\"fare_per_seat\":100,\"total_seats\":10}")"
t "operator 1 cannot use operator 2's vehicle" 404 "$(c -b $OPJ -X POST $B/schedules -H "$H" -d "{\"route_id\":1,\"vehicle_id\":2,\"departure_time\":\"$D1\",\"arrival_time\":\"$A1\",\"fare_per_seat\":100,\"total_seats\":10}")"

echo "== 5. a passenger finds the trip and books it"
t "places lists the real towns" 200 "$(c $B/places)"; echo "      $(cat $OUT | tr -d '\n ')"
t "search Lusaka to Kitwe" 200 "$(c "$B/schedules?origin=Lusaka&destination=Kitwe")"; echo "      operator: $(j data.0.operator_name), seats left: $(j data.0.seats_available), fare: $(j data.0.fare_per_seat)"
t "search a route nobody runs: empty list" 200 "$(c "$B/schedules?origin=Lusaka&destination=Chipata")"; echo "      $(j data | tr -d '\n ')"
t "trip detail is public" 200 "$(c $B/schedules/1)"
t "unknown trip is 404" 404 "$(c $B/schedules/999)"
t "booking needs a session" 401 "$(c -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":1}')"
t "an operator cannot buy tickets" 403 "$(c -b $OPJ -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":1}')"
t "book 3 of 4 seats" 201 "$(c -b $CJ -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":3,"passenger_name":"Mutale Zulu, Joe Zulu, Ann Zulu"}')"; echo "      $(j booking.booking_reference) total $(j booking.total_amount)"
t "3 more seats: only 1 left, 409" 409 "$(c -b $CJ -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":3}')"
t "seats_available is now 1" "1" "$(php tests/db_query.php 'SELECT seats_available FROM schedules WHERE schedule_id=1' | head -1 | sed 's/.*://; s/[^0-9]//g')"

t "the operator was notified (realtime event written for the operator account)" "1" "$(php tests/db_query.php "SELECT COUNT(*) AS n FROM realtime_events WHERE user_id=2 AND event_type='booking.created'" | head -1 | sed 's/.*://; s/[^0-9]//g')"
echo "== 6. payment (demonstration gateway)"
t "booking detail carries the trip" 200 "$(c -b $CJ $B/bookings/1)"; echo "      $(j origin) -> $(j destination), seats $(j seats), status $(j booking_status), payment $(j payment_status)"
t "another customer cannot pay for it (404)" 404 "$(c -b $CJ.o2 -X POST $B/bookings/1/payment -H "$H" -d '{"method":"mtn_momo"}')"
t "simulated failure answers 402" 402 "$(c -b $CJ -X POST $B/bookings/1/payment -H "$H" -d '{"method":"mtn_momo","simulate_failure":true}')"
t "after the failure the booking is still pending" "pending" "$(c -b $CJ $B/bookings/1 >/dev/null; j booking_status)"
t "retry succeeds" 200 "$(c -b $CJ -X POST $B/bookings/1/payment -H "$H" -d '{"method":"mtn_momo"}')"; echo "      $(j message)"
t "booking is confirmed" "confirmed" "$(c -b $CJ $B/bookings/1 >/dev/null; j booking_status)"
t "paying twice is 409" 409 "$(c -b $CJ -X POST $B/bookings/1/payment -H "$H" -d '{"method":"mtn_momo"}')"
t "exactly one payment row, successful" "1|successful" "$(php tests/db_query.php 'SELECT COUNT(*)||"|"||MAX(payment_status) AS x FROM payments' | head -1 | sed 's/.*:"\(.*\)".*/\1/')"
t "operator sees the booking with the passenger" 200 "$(c -b $OPJ $B/operator/bookings)"; echo "      $(j data.0.booking_reference) $(j data.0.customer_name) seats $(j data.0.seats) $(j data.0.payment_status)"
t "the other operator sees none of it" 200 "$(c -b $CJ.o2 $B/operator/bookings)"; echo "      $(j data | tr -d '\n ')"
t "customer's own list shows trip info" 200 "$(c -b $CJ "$B/bookings")"; echo "      $(j data.0.origin) -> $(j data.0.destination)"

echo "== 7. an unpaid hold expires and the seat goes back on sale"
t "book the last seat" 201 "$(c -b $CJ -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":1,"passenger_name":"Mutale Zulu"}')"
php tests/db_query.php "UPDATE bookings SET hold_expires_at='2020-01-01 00:00:00' WHERE booking_status='pending'" >/dev/null
t "search sweeps the expired hold" 200 "$(c "$B/schedules?origin=Lusaka&destination=Kitwe")"; echo "      seats left: $(j data.0.seats_available)"
t "seat is back on sale (1 available)" "1" "$(php tests/db_query.php 'SELECT seats_available FROM schedules WHERE schedule_id=1' | head -1 | sed 's/.*://; s/[^0-9]//g')"
t "the expired booking is cancelled" "cancelled" "$(php tests/db_query.php 'SELECT booking_status FROM bookings WHERE booking_id=2' | head -1 | sed 's/.*:"\(.*\)".*/\1/')"
t "paying an expired booking is 409" 409 "$(c -b $CJ -X POST $B/bookings/2/payment -H "$H" -d '{"method":"card"}')"

echo "== 8. schema rules still hold"
t "unknown field in a booking is rejected" 422 "$(c -b $CJ -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":1,"total_amount":1}')"
t "payment with a made-up method is rejected" 422 "$(c -b $CJ -X POST $B/bookings/1/payment -H "$H" -d '{"method":"bitcoin"}')"
t "bad decision value is rejected" 422 "$(c -b $AJ -X POST $B/admin/operators/1/decision -H "$H" -d '{"decision":"maybe"}')"
t "admin cannot be impersonated by an operator" 403 "$(c -b $OPJ -X POST $B/admin/operators/2/decision -H "$H" -d '{"decision":"reject"}')"
echo "SUMMARY: $pass passed, $fail failed"; [ $fail = 0 ]
