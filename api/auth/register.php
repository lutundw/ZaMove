<?php
declare(strict_types=1);

/**
 * POST /api/auth/register.php
 *
 * Creates a customer or operator account.
 * Body (JSON): { "role", "full_name", "email", "phone", "password" }
 *
 *   201 Created              account created
 *   400 Bad Request          empty body / malformed JSON / not a JSON object
 *   405 Method Not Allowed   not POST
 *   409 Conflict             email already registered
 *   413 Payload Too Large    body over 10 KB
 *   415 Unsupported Media    Content-Type is not application/json
 *   422 Unprocessable Entity one or more fields failed validation
 *   500 Server Error         anything unexpected (details are logged, never sent)
 */

require_once __DIR__ . '/../lib/middleware.php';
require_once __DIR__ . '/../lib/db.php';

ini_set('display_errors', '0');          // never leak PHP errors or SQL into the response

set_exception_handler(function (Throwable $e): void {
    error_log('[zamove] ' . $e::class . ': ' . $e->getMessage());
    problem(500, 'Internal Server Error', 'Something went wrong on our side. Please try again later.');
});

// ---- middleware chain ------------------------------------------------------
security_headers();
require_method('POST');
require_json_content_type();
$body  = read_json_body();
$clean = validate_or_422(REGISTER_SCHEMA, $body);     // re-validates EVERY field, whatever the browser did

// ---- business logic --------------------------------------------------------
$hash   = password_hash($clean['password'], PASSWORD_DEFAULT);          // bcrypt/argon2, salted
$status = $clean['role'] === 'operator' ? 'pending' : 'active';          // operators wait for admin approval
$phone  = normalise_zm_phone($clean['phone']);

try {
    $stmt = db()->prepare(
        'INSERT INTO users (full_name, email, phone, password_hash, role, account_status)
         VALUES (:full_name, :email, :phone, :password_hash, :role, :account_status)'
    );
    $stmt->execute([
        ':full_name'      => $clean['full_name'],
        ':email'          => $clean['email'],
        ':phone'          => $phone,
        ':password_hash'  => $hash,
        ':role'           => $clean['role'],
        ':account_status' => $status,
    ]);
    $userId = (int) db()->lastInsertId();
} catch (PDOException $e) {
    if (is_duplicate_key($e)) {                                          // database error -> HTTP 409
        problem(409, 'Conflict', 'An account with this email already exists.', [
            'errors' => [[
                'field'   => 'email',
                'code'    => 'already_registered',
                'message' => 'This email is already registered. Try logging in instead.',
            ]],
        ]);
    }
    throw $e;                                                            // handled by set_exception_handler -> 500
}

json_response(201, [
    'message' => $status === 'pending'
        ? 'Account created. An administrator must approve your operator profile before you can sell tickets.'
        : 'Account created. You can now log in.',
    'user' => [
        'user_id'        => $userId,
        'full_name'      => $clean['full_name'],       // the SANITISED value, not what was typed
        'email'          => $clean['email'],
        'phone'          => $phone,
        'role'           => $clean['role'],
        'account_status' => $status,
    ],
]);
