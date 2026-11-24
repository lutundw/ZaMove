<?php
declare(strict_types=1);
/**
 * LAB 7 (NEW FILE) - query-plan and timing benchmark for Task 3.
 *
 *   php tools/bench_queries.php <label> [runs=30]       e.g.  before_index  /  after_index
 *
 * For each hot query it prints the planner output (SQLite: EXPLAIN QUERY PLAN, MySQL: EXPLAIN) and then runs the
 * query `runs` times with different real parameters (from tools/bench_params.json), reporting median / p95 / mean in ms.
 * Results are also saved to evidence/lab7/bench_<label>.json. On MySQL 8 use tests/explain_mysql.sql for EXPLAIN ANALYZE.
 */
require_once __DIR__ . '/../api/lib/db.php';
$label = $argv[1] ?? 'run'; $runs = (int) ($argv[2] ?? 30);
$P = json_decode((string) file_get_contents(__DIR__ . '/bench_params.json'), true);
$pdo = db(); $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
$now = gmdate('Y-m-d H:i:s');
$d0 = $P['date'] . ' 00:00:00'; $d1 = gmdate('Y-m-d', strtotime($P['date'] . ' UTC') + 86400 * 14) . ' 00:00:00';   // a two-week window
$pick = fn(array $a, int $i) => $a[$i % count($a)];

$Q = [
 'Q1 schedule search (origin + destination + date window, soonest first)' => [
   'sql' => "SELECT s.schedule_id, r.origin, r.destination, s.departure_at, s.fare_per_seat, s.seats_available
               FROM schedules s JOIN routes r ON r.route_id = s.route_id
              WHERE s.schedule_status = 'scheduled' AND s.departure_at >= ? AND s.departure_at < ? AND s.seats_available > 0
                AND r.route_status = 'active' AND r.origin = ? AND r.destination = ?
              ORDER BY s.departure_at ASC, s.schedule_id ASC LIMIT 20",
   'args' => fn($i) => [$d0, $d1, $pick($P['routes'], $i)[0], $pick($P['routes'], $i)[1]]],
 'Q2 my bookings (customer + status, newest first)' => [
   'sql' => "SELECT booking_id, booking_reference, booking_status, total_amount, created_at FROM bookings
              WHERE customer_id = ? AND booking_status = ? ORDER BY created_at DESC, booking_id DESC LIMIT 20",
   'args' => fn($i) => [$pick($P['customers'], $i), $i % 2 ? 'confirmed' : 'completed']],
 'Q3 booking lookup by reference (UNIQUE key exists in both runs)' => [
   'sql' => "SELECT booking_id, booking_status, total_amount FROM bookings WHERE booking_reference = ?",
   'args' => fn($i) => [$pick($P['references'], $i)]],
 'Q4 bookings of one departure, counted per status (FK schedule_id)' => [
   'sql' => "SELECT booking_status, COUNT(*) AS n FROM bookings WHERE schedule_id = ? GROUP BY booking_status",
   'args' => fn($i) => [$pick($P['schedules'], $i)]],
 'Q5 join: bookings per departure of one route (schedules -> bookings)' => [
   'sql' => "SELECT s.schedule_id, s.departure_at, COUNT(b.booking_id) AS n
               FROM schedules s JOIN bookings b ON b.schedule_id = s.schedule_id
              WHERE s.route_id = ? GROUP BY s.schedule_id, s.departure_at ORDER BY s.departure_at LIMIT 20",
   'args' => fn($i) => [$pick($P['route_ids'], $i)]],
];

$out = []; $txt = '';
$say = function (string $s) use (&$txt) { echo $s; $txt .= $s; };
$say("== Benchmark: $label | driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . " | rows: schedules " .
     $pdo->query('SELECT COUNT(*) FROM schedules')->fetchColumn() . ', bookings ' . $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn() . " | runs per query: $runs\n\n");
foreach ($Q as $name => $q) {
    $say("### $name\n" . preg_replace('/\s+/', ' ', trim($q['sql'])) . "\n");
    $args0 = ($q['args'])(0);
    $plan = $pdo->prepare(($sqlite ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ') . $q['sql']); $plan->execute($args0);
    foreach ($plan->fetchAll() as $r) $say('  PLAN: ' . ($sqlite ? $r['detail'] : json_encode($r, JSON_UNESCAPED_SLASHES)) . "\n");
    $stmt = $pdo->prepare($q['sql']); $stmt->execute($args0); $stmt->fetchAll();          // warm-up
    $ms = []; $rows = 0;
    for ($i = 1; $i <= $runs; $i++) {
        $t = hrtime(true); $stmt->execute(($q['args'])($i)); $rows = count($stmt->fetchAll()); $ms[] = (hrtime(true) - $t) / 1e6;
    }
    sort($ms);
    $r = ['median' => $ms[(int) floor(count($ms) / 2)], 'p95' => $ms[(int) min(count($ms) - 1, ceil(count($ms) * 0.95) - 1)], 'mean' => array_sum($ms) / count($ms), 'min' => $ms[0], 'max' => end($ms)];
    $out[$name] = $r;
    $say(sprintf("  TIME: median %.3f ms | p95 %.3f ms | mean %.3f ms | min %.3f | max %.3f  (last run returned %d rows)\n\n", $r['median'], $r['p95'], $r['mean'], $r['min'], $r['max'], $rows));
}
@mkdir(__DIR__ . '/../evidence/lab7', 0777, true);
file_put_contents(__DIR__ . "/../evidence/lab7/bench_$label.json", json_encode($out, JSON_PRETTY_PRINT));
file_put_contents(__DIR__ . "/../evidence/lab7/explain_$label.txt", $txt);
