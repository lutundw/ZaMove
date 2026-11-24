<?php
declare(strict_types=1);
/**
 * LAB 7 (NEW FILE) - fills the database with a large, realistic data set for the indexing benchmark.
 *
 *   php tools/seed_bench.php [--users=5000] [--operators=30] [--routes=400] [--schedules=300000] [--bookings=500000]
 *
 * Works on MySQL (XAMPP) and SQLite (the sandbox). Rows are inserted in batches of 500 inside transactions.
 * Run it AFTER `php tools/migrate.php up --step=3` (tables + seats_available, but NO performance indexes yet),
 * benchmark, then run migration 004 and benchmark again.
 * It also writes tools/bench_params.json (real ids/towns to use as test inputs).
 * NOTE: benchmark data only. Seat counters are random and are not reconciled with the bookings rows.
 */
require_once __DIR__ . '/../api/lib/db.php';

$o = ['users' => 5000, 'operators' => 30, 'routes' => 400, 'schedules' => 300000, 'bookings' => 500000];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--(\w+)=(\d+)$/', $a, $m) && isset($o[$m[1]])) $o[$m[1]] = (int) $m[2];
mt_srand(7461);                                                   // same data on every run

$pdo = db();
$sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
if ($sqlite) { $pdo->exec('PRAGMA synchronous = OFF; PRAGMA journal_mode = MEMORY; PRAGMA foreign_keys = OFF'); }  // set-up speed only
else         { $pdo->exec('SET FOREIGN_KEY_CHECKS = 0; SET unique_checks = 0'); }

function batch_insert(PDO $pdo, string $table, array $cols, iterable $rows, int $size = 500): int
{
    $n = 0; $buf = [];
    $flush = function () use (&$buf, $pdo, $table, $cols) {
        if (!$buf) return;
        $one = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($buf), $one)))
            ->execute(array_merge(...$buf));
        $buf = [];
    };
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $buf[] = $r; $n++;
        if (count($buf) >= $size) { $flush(); if ($n % 50000 === 0) { $pdo->commit(); $pdo->beginTransaction(); } }
    }
    $flush(); $pdo->commit();
    return $n;
}

$t0 = microtime(true);
$towns = ['Lusaka','Ndola','Kitwe','Livingstone','Kabwe','Chipata','Solwezi','Mongu','Kasama','Choma','Mansa','Mazabuka','Kafue','Chingola','Mufulira','Luanshya','Chirundu','Siavonga','Petauke','Monze','Kapiri Mposhi','Mkushi','Serenje','Mpika','Nakonde','Mbala','Senanga','Sesheke','Kalomo','Nyimba'];
$hash  = password_hash('Seed2026pass', PASSWORD_DEFAULT);          // one hash for all seed accounts (hashing 5000 passwords would take minutes)
$now   = time();
$fmt   = fn(int $t) => gmdate('Y-m-d H:i:s', $t);

// users: first $operators are operators, rest customers
$users = batch_insert($pdo, 'users', ['full_name', 'email', 'phone', 'password_hash', 'role', 'account_status', 'created_at'],
    (function () use ($o, $hash, $fmt, $now) {
        $total = $o['users'] + $o['operators'];
        for ($i = 1; $i <= $total; $i++)
            yield ["Seed User $i", "seed$i@example.com", '0971' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), $hash,
                   $i <= $o['operators'] ? 'operator' : 'customer', 'active', $fmt($now - mt_rand(0, 400) * 86400)];
    })());
batch_insert($pdo, 'operators', ['user_id', 'business_name', 'business_type', 'business_address', 'verification_status'],
    (function () use ($o) { for ($i = 1; $i <= $o['operators']; $i++) yield [$i, "Seed Coaches $i", 'bus', 'Cairo Road, Lusaka', 'approved']; })());
batch_insert($pdo, 'vehicles', ['operator_id', 'registration_number', 'vehicle_type', 'seat_capacity', 'vehicle_status'],
    (function () use ($o) { for ($i = 1; $i <= $o['operators'] * 3; $i++) yield [($i - 1) % $o['operators'] + 1, "ABC " . (1000 + $i), 'bus', 49, 'active']; })());
batch_insert($pdo, 'routes', ['operator_id', 'origin', 'destination', 'distance_km', 'base_fare', 'route_status'],
    (function () use ($o, $towns) {
        for ($i = 1; $i <= $o['routes']; $i++) {
            $a = $towns[mt_rand(0, count($towns) - 1)]; do { $b = $towns[mt_rand(0, count($towns) - 1)]; } while ($b === $a);
            yield [mt_rand(1, $o['operators']), $a, $b, mt_rand(50, 900), mt_rand(80, 600), $i % 25 === 0 ? 'inactive' : 'active'];
        }
    })());
$vehCount = $o['operators'] * 3;
batch_insert($pdo, 'schedules', ['route_id', 'vehicle_id', 'departure_at', 'arrival_at', 'fare_per_seat', 'total_seats', 'schedule_status', 'seats_available'],
    (function () use ($o, $fmt, $now, $vehCount) {
        for ($i = 1; $i <= $o['schedules']; $i++) {
            $dep = $now + mt_rand(-30 * 86400, 180 * 86400);                    // some in the past, most in the next 6 months
            $seats = mt_rand(30, 65);
            yield [mt_rand(1, $o['routes']), mt_rand(1, $vehCount), $fmt($dep), $fmt($dep + mt_rand(3, 12) * 3600), mt_rand(80, 600),
                   $seats, $dep < $now ? 'completed' : 'scheduled', mt_rand(0, $seats)];
        }
    })());
$statuses = ['pending', 'confirmed', 'confirmed', 'confirmed', 'completed', 'completed', 'cancelled', 'in_transit'];
$customerBase = $o['operators'] + 1;
batch_insert($pdo, 'bookings', ['booking_reference', 'customer_id', 'operator_id', 'booking_type', 'schedule_id', 'booking_status', 'total_amount', 'currency', 'created_at'],
    (function () use ($o, $statuses, $fmt, $now, $customerBase) {
        for ($i = 1; $i <= $o['bookings']; $i++)
            yield [sprintf('ZM-SEED-%07d', $i), mt_rand($customerBase, $customerBase + $o['users'] - 1), mt_rand(1, $o['operators']), 'passenger',
                   mt_rand(1, $o['schedules']), $statuses[mt_rand(0, count($statuses) - 1)], mt_rand(80, 1500), 'ZMW', $fmt($now - mt_rand(0, 365 * 86400))];
    })());

// real values to use as benchmark inputs
$hot = $pdo->query("SELECT r.origin, r.destination, COUNT(*) c FROM routes r JOIN schedules s ON s.route_id = r.route_id GROUP BY r.origin, r.destination ORDER BY c DESC LIMIT 25")->fetchAll();
$params = [
    'routes'    => array_map(fn($r) => [$r['origin'], $r['destination']], $hot),
    'customers' => range($customerBase, $customerBase + 199),
    'references' => array_map(fn($i) => sprintf('ZM-SEED-%07d', $i), array_map(fn() => mt_rand(1, $o['bookings']), range(1, 60))),
    'schedules' => array_map(fn() => mt_rand(1, $o['schedules']), range(1, 60)),
    'route_ids' => array_map(fn() => mt_rand(1, $o['routes']), range(1, 40)),
    'date'      => gmdate('Y-m-d', $now + 30 * 86400),
    'counts'    => $o,
];
file_put_contents(__DIR__ . '/bench_params.json', json_encode($params, JSON_PRETTY_PRINT));
foreach (['users', 'operators', 'routes', 'schedules', 'bookings'] as $t) echo str_pad($t, 12), $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(), "\n";
printf("seeded in %.1f s\n", microtime(true) - $t0);
