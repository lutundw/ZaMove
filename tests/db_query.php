<?php
/**
 * Print the result of one SQL statement using the same connection settings as the API.
 *   php tests/db_query.php "SELECT user_id, email, role FROM users"
 * Works on XAMPP (MySQL) and, for the automated tests, SQLite. Used to collect the
 * "before / after logout" evidence for the report.
 */
require __DIR__ . '/../api/lib/db.php';
$sql = $argv[1] ?? '';
if ($sql === '') { fwrite(STDERR, "usage: php tests/db_query.php \"SQL\"\n"); exit(1); }
$stmt = db()->query($sql);
if ($stmt->columnCount() === 0) { echo "OK (", $stmt->rowCount(), " row(s) affected)\n"; exit; }
$rows = $stmt->fetchAll();
if (!$rows) { echo "(0 rows)\n"; exit; }
foreach ($rows as $r) { echo json_encode($r, JSON_UNESCAPED_SLASHES), "\n"; }
echo "(", count($rows), " row(s))\n";
