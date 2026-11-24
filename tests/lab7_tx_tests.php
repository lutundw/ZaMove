<?php
declare(strict_types=1);
/**
 * LAB 7 Task 2: ACID tests for create_booking_tx().   php tests/lab7_tx_tests.php   (DB chosen like the API)
 *   --worker <schedule_id> <user_id>   used internally by the concurrency test
 */
putenv('ZAMOVE_ENV=test');
require __DIR__ . '/../api/lib/db.php';
require __DIR__ . '/../api/lib/bookings_tx.php';

if (($argv[1] ?? '') === '--worker') {                         // one competing customer
    try { create_booking_tx(db(), (int) $argv[3], (int) $argv[2], 1, null); echo "OK\n"; }
    catch (BookingFailure $e) { echo "CONFLICT {$e->status}\n"; }
    catch (Throwable $e)      { echo "ERROR " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
    exit;
}

$pdo = db(); $pass = 0; $fail = 0;
function check(string $name, bool $cond, string $info = ''): void { global $pass, $fail; echo ($cond ? 'RESULT: PASS' : 'RESULT: FAIL') . " ($name)" . ($info ? " $info" : '') . "\n"; $cond ? $pass++ : $fail++; }
function n(string $sql, array $a = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($a); return (int) $s->fetchColumn(); }
function counts(): array { return ['bookings' => n('SELECT COUNT(*) FROM bookings'), 'details' => n('SELECT COUNT(*) FROM passenger_booking_details'),
                                   'payments' => n('SELECT COUNT(*) FROM payments'), 'history' => n('SELECT COUNT(*) FROM booking_status_history')]; }
function show(string $label, array $c, int $seats): void { echo "  $label: bookings={$c['bookings']} details={$c['details']} payments={$c['payments']} history={$c['history']} seats_available=$seats\n"; }

// ---- seed -----------------------------------------------------------------------------------
$pdo->exec("INSERT INTO users (full_name,email,phone,password_hash,role) VALUES ('Op One','op@example.com','+260971000001','x','operator'),('Cust One','c1@example.com','+260971000002','x','customer')");
$pdo->exec("INSERT INTO operators (user_id,business_name,business_type,business_address,verification_status) VALUES (1,'Test Coaches','bus','Lusaka','approved')");
$pdo->exec("INSERT INTO vehicles (operator_id,registration_number,vehicle_type,seat_capacity) VALUES (1,'BAA 1234','bus',40)");
$pdo->exec("INSERT INTO routes (operator_id,origin,destination,base_fare) VALUES (1,'Lusaka','Kitwe',260)");
$dep = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
$pdo->exec("INSERT INTO schedules (route_id,vehicle_id,departure_at,fare_per_seat,total_seats,seats_available) VALUES (1,1,'$dep',260,40,40),(1,1,'$dep',260,5,5)");
$S = 1; $CUST = 2;

echo "### T1 successful booking: all rows written, seats taken\n";
$c0 = counts(); $s0 = n('SELECT seats_available FROM schedules WHERE schedule_id=?', [$S]); show('before', $c0, $s0);
$b = create_booking_tx($pdo, $CUST, $S, 2, 'Test Passenger');
$c1 = counts(); $s1 = n('SELECT seats_available FROM schedules WHERE schedule_id=?', [$S]); show('after ', $c1, $s1);
check('T1 COMMIT wrote booking, details, payment and history (one row each)', $c1['bookings'] == $c0['bookings'] + 1 && $c1['details'] == $c0['details'] + 1 && $c1['payments'] == $c0['payments'] + 1 && $c1['history'] == $c0['history'] + 1);
check('T1 seats_available went 40 -> 38', $s0 == 40 && $s1 == 38);
check('T1 total computed on the server (2 x 260 = 520.00)', abs($b['total_amount'] - 520.0) < 0.001);

foreach (['booking' => 'after INSERT bookings', 'details' => 'after INSERT passenger_booking_details', 'payment' => 'after INSERT payments', 'history' => 'after INSERT booking_status_history (last step)'] as $step => $when) {
    echo "### T2.$step failure injected $when: everything must roll back\n";
    $c0 = counts(); $s0 = n('SELECT seats_available FROM schedules WHERE schedule_id=?', [$S]); show('before', $c0, $s0);
    putenv("ZAMOVE_TEST_FAIL_AT=$step"); $msg = '';
    try { create_booking_tx($pdo, $CUST, $S, 2, null); } catch (Throwable $e) { $msg = $e->getMessage(); }
    putenv('ZAMOVE_TEST_FAIL_AT=');
    $c1 = counts(); $s1 = n('SELECT seats_available FROM schedules WHERE schedule_id=?', [$S]); show('after ', $c1, $s1);
    echo "  exception: $msg\n";
    check("T2.$step exception raised", str_contains($msg, "injected failure at step '$step'"));
    check("T2.$step ROLLBACK left no partial rows", $c1 === $c0);
    check("T2.$step ROLLBACK returned the seats", $s1 === $s0);
    check("T2.$step connection usable and no transaction left open", !$pdo->inTransaction() && n('SELECT 1') === 1);
}

echo "### T3 sold out: request more seats than remain\n";
$c0 = counts(); $code = 0;
try { create_booking_tx($pdo, $CUST, $S, 10 + 30, null); } catch (BookingFailure $e) { $code = $e->status; }
check('T3 answered 409 Conflict', $code === 409); check('T3 nothing was written', counts() === $c0);

echo "### T4 unknown schedule\n"; $code = 0;
try { create_booking_tx($pdo, $CUST, 999, 1, null); } catch (BookingFailure $e) { $code = $e->status; }
check('T4 answered 404', $code === 404);

echo "### T5 consistency: the database refuses bad data by itself\n";
$err = ''; try { $pdo->exec('UPDATE schedules SET seats_available = -1 WHERE schedule_id = 2'); } catch (PDOException $e) { $err = $e->getMessage(); }
check('T5 CHECK (seats_available >= 0) rejects a negative counter', $err !== '', substr($err, 0, 90));
$err = ''; try { $pdo->exec("INSERT INTO bookings (booking_reference,customer_id,operator_id,booking_type,schedule_id,booking_status,total_amount) VALUES ('ZM-X',999,1,'passenger',1,'pending',1)"); } catch (PDOException $e) { $err = $e->getMessage(); }
check('T5 foreign key rejects a booking for a customer that does not exist', $err !== '', substr($err, 0, 90));

echo "### T6 isolation: 12 customers compete for the last 5 seats of schedule 2 at the same time\n";
$procs = [];
for ($i = 0; $i < 12; $i++) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, '--worker', '2', (string) $CUST], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$res = [];
foreach ($procs as $i => $p) { $res[] = trim(stream_get_contents($pipes[$i][1])); proc_close($p); }
$vals = array_count_values($res); ksort($vals);
echo '  outcomes: ' . json_encode($vals) . "\n";
$left = n('SELECT seats_available FROM schedules WHERE schedule_id = 2'); $sold = n("SELECT COALESCE(SUM(d.number_of_seats),0) FROM bookings b JOIN passenger_booking_details d ON d.booking_id=b.booking_id WHERE b.schedule_id=2");
echo "  seats_available now $left, seats sold $sold\n";
check('T6 exactly 5 requests succeeded', ($vals['OK'] ?? 0) === 5);
check('T6 the other 7 got 409 (no overselling)', ($vals['CONFLICT 409'] ?? 0) === 7);
check('T6 no unexpected errors', count(array_filter($res, fn($r) => str_starts_with($r, 'ERROR'))) === 0);
check('T6 seats_available = 0 and never negative; seats sold = 5', $left === 0 && $sold === 5);

echo "SUMMARY: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
