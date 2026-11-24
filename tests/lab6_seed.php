<?php
/** Seeds the Lab 6 test fixtures (SQLite tests only): 2 customers, 2 operators (2 companies), 1 admin, 2 bookings. */
require __DIR__ . '/../api/lib/db.php';
$pdo = db();
$h = password_hash('Lab6TestPass1', PASSWORD_DEFAULT);
$u = $pdo->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, account_status) VALUES (?, ?, '+260971111111', ?, ?, 'active')");
foreach ([['Alice Customer', 'alice@lab6.test', 'customer'], ['Bob Customer', 'bob@lab6.test', 'customer'],
          ['Olive Operator', 'olive@lab6.test', 'operator'], ['Oscar Operator', 'oscar@lab6.test', 'operator'],
          ['Ada Admin', 'ada@lab6.test', 'admin']] as [$n, $e, $r]) { $u->execute([$n, $e, $h, $r]); }
$o = $pdo->prepare("INSERT INTO operators (user_id, business_name, verification_status) VALUES (?, ?, 'approved')");
$o->execute([3, 'Olive Express']); $o->execute([4, 'Oscar Coaches']);               // operator_id 1 and 2
$b = $pdo->prepare("INSERT INTO bookings (booking_reference, customer_id, operator_id, booking_type, schedule_id, booking_status, total_amount) VALUES (?, ?, ?, 'passenger', 1, 'confirmed', ?)");
$b->execute(['ZM-LAB6-0001', 1, 1, 520.00]);   // booking 1: Alice, Olive Express
$b->execute(['ZM-LAB6-0002', 2, 2, 300.00]);   // booking 2: Bob, Oscar Coaches
echo "seeded\n";
