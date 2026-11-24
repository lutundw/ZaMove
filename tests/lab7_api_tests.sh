#!/usr/bin/env bash
# LAB 7: the transactional endpoint over HTTP (sandbox: php -S + SQLite).   bash tests/lab7_api_tests.sh
cd "$(dirname "$0")/.."
DB=/tmp/zm_l7api_$$.db; PORT=8181; JAR=$(mktemp)
export ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE="$DB" ZAMOVE_ENV=test ZAMOVE_RL_OFF=1 ZAMOVE_ALLOW_HTTP=1
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
B="http://127.0.0.1:$PORT/api/v1"; H='Content-Type: application/json'; pass=0; fail=0
t(){ if [ "$2" = "$3" ]; then echo "RESULT: PASS ($1: expected $2, got $3)"; pass=$((pass+1)); else echo "RESULT: FAIL ($1: expected $2, got $3)"; fail=$((fail+1)); fi; }
code(){ curl -s -o /tmp/zm_body.txt -w '%{http_code}' "$@"; }
t "A1 booking without a session" 401 "$(code -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":1}')"
t "A2 login" 200 "$(code -c $JAR -X POST $B/sessions -H "$H" -d '{"email":"cust@example.com","password":"Str0ng!Passw0rd"}')"
t "A3 public schedule search" 200 "$(code "$B/schedules?origin=Lusaka&destination=Kitwe")"; echo "  $(cat /tmp/zm_body.txt | cut -c1-200)"
t "A4 invalid body (seats 0) is rejected by the schema" 422 "$(code -b $JAR -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":0}')"
t "A5 unknown field is rejected" 422 "$(code -b $JAR -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":1,"total_amount":1}')"
t "A6 book 2 of 3 seats" 201 "$(code -b $JAR -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":2,"passenger_name":"Test Passenger"}')"; echo "  $(cat /tmp/zm_body.txt | cut -c1-300)"
t "A7 asking for 2 more (1 left) answers 409" 409 "$(code -b $JAR -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":2}')"; echo "  $(cat /tmp/zm_body.txt | cut -c1-260)"
t "A8 take the last seat" 201 "$(code -b $JAR -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":1}')"
t "A9 sold out now" 409 "$(code -b $JAR -X POST $B/bookings -H "$H" -d '{"schedule_id":1,"seats":1}')"
t "A10 unknown departure" 404 "$(code -b $JAR -X POST $B/bookings -H "$H" -d '{"schedule_id":999,"seats":1}')"
t "A11 seats_available is 0 in the database" "0" "$(php tests/db_query.php 'SELECT seats_available FROM schedules WHERE schedule_id=1' | head -1 | sed 's/.*://; s/[^0-9]//g')"
t "A12 my bookings lists the 2 bookings" 200 "$(code -b $JAR "$B/bookings")"; echo "  total: $(grep -o '"total":[0-9]*' /tmp/zm_body.txt)"
echo "SUMMARY: $pass passed, $fail failed"; [ $fail = 0 ]
