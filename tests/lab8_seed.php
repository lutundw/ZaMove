<?php
/** LAB 8 (NEW FILE) - test fixtures (SQLite only): Lab 6 users/bookings + a known session cookie per user + 100 extra users for the stress test. */
require __DIR__ . '/lab6_seed.php';
$pdo = db();
$tokens = [];
$ins = $pdo->prepare('INSERT INTO sessions (session_id_hash, user_id, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?)');
$now = gmdate('Y-m-d H:i:s'); $exp = gmdate('Y-m-d H:i:s', time() + 3600);
$mk = function (int $uid) use ($ins, $now, $exp, &$tokens) { $t = hash('sha256', 'lab8-token-' . $uid); $ins->execute([hash('sha256', $t), $uid, $now, $now, $exp]); $tokens[$uid] = $t; };
foreach ([1, 2, 3, 4, 5] as $u) { $mk($u); }
$h = password_hash('Lab8StressPass1', PASSWORD_DEFAULT);
$u = $pdo->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, account_status) VALUES (?, ?, '+260972222222', ?, 'customer', 'active')");
for ($i = 1; $i <= 100; $i++) { $u->execute(["Stress User $i", "stress$i@lab8.test", $h]); $mk((int) $pdo->lastInsertId()); }
file_put_contents($argv[1] ?? '/tmp/lab8_tokens.json', json_encode($tokens));
echo "seeded " . count($tokens) . " sessions\n";
