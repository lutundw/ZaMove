<?php
/**
 * Create (or reset) an administrator account. Admins can never register through the API
 * (the contract only allows customer and operator), so they are created here, on the server.
 *
 *   php tools/create_admin.php admin@zamove.example "Platform Admin" "ChooseAStrongPass1"
 */
require __DIR__ . '/../api/lib/db.php';
[$self, $email, $name, $password] = $argv + [null, null, null, null];
if (!$email || !$name || !$password) { fwrite(STDERR, "usage: php tools/create_admin.php EMAIL \"FULL NAME\" PASSWORD\n"); exit(1); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) { fwrite(STDERR, "invalid email or password shorter than 8 characters\n"); exit(1); }

$stmt = db()->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, account_status) VALUES (?, ?, '+260970000000', ?, 'admin', 'active')");
$stmt->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
echo "Admin created: ", strtolower($email), "\n";
