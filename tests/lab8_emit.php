<?php
/** LAB 8 (NEW FILE) - test helper: writes one system event for every user id >= 6 (what the HTTP API does through rt_emit). SQLite tests only. */
require __DIR__ . '/../api/lib/realtime.php';
$pdo = db();
$ids = $pdo->query('SELECT user_id FROM users WHERE user_id >= 6')->fetchAll(PDO::FETCH_COLUMN);
$ins = $pdo->prepare("INSERT INTO realtime_events (user_id, booking_id, event_type, payload, created_at) VALUES (?, NULL, 'booking.message', ?, ?)");
foreach ($ids as $u) { $ins->execute([$u, json_encode(['from' => 'System', 'text' => 'broadcast test', 'booking_id' => null]), gmdate('Y-m-d H:i:s')]); }
echo count($ids) . " events written\n";
