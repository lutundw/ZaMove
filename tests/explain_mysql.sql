-- LAB 7, Task 3 on XAMPP MySQL / MariaDB. Run in phpMyAdmin (SQL tab) or:  C:\xampp\mysql\bin\mysql -u root zamove_bench < tests\explain_mysql.sql
-- 1) Use an EMPTY database, run "php tools/migrate.php up --step=3", then "php tools/seed_bench.php" (about 300 000 schedules).
-- 2) Run this file BEFORE and AFTER "php tools/migrate.php up" (migration 004). Screenshot both outputs for the report.
-- MySQL 8.0.18+ supports EXPLAIN ANALYZE (actual rows and time). MariaDB: use  ANALYZE <select>;  instead of EXPLAIN ANALYZE.
-- Replace the town names and the customer id with values from tools/bench_params.json.

EXPLAIN ANALYZE
SELECT s.schedule_id, r.origin, r.destination, s.departure_at, s.fare_per_seat, s.seats_available
  FROM schedules s JOIN routes r ON r.route_id = s.route_id
 WHERE s.schedule_status = 'scheduled' AND s.departure_at >= '2026-11-20' AND s.departure_at < '2026-12-04'
   AND s.seats_available > 0 AND r.route_status = 'active' AND r.origin = 'Lusaka' AND r.destination = 'Kitwe'
 ORDER BY s.departure_at ASC, s.schedule_id ASC LIMIT 20;

EXPLAIN ANALYZE
SELECT booking_id, booking_reference, booking_status, total_amount, created_at FROM bookings
 WHERE customer_id = 42 AND booking_status = 'pending' ORDER BY created_at DESC, booking_id DESC LIMIT 20;

EXPLAIN ANALYZE
SELECT booking_status, COUNT(*) AS n FROM bookings WHERE schedule_id = 1234 GROUP BY booking_status;

EXPLAIN ANALYZE
SELECT s.schedule_id, s.departure_at, COUNT(b.booking_id) AS n
  FROM schedules s JOIN bookings b ON b.schedule_id = s.schedule_id
 WHERE s.route_id = 7 GROUP BY s.schedule_id, s.departure_at ORDER BY s.departure_at LIMIT 20;
-- Before the index you should see "Table scan" / type=ALL; after it, "Index range scan" / "Index lookup" with far fewer rows.
