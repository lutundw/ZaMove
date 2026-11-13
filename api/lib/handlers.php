<?php
declare(strict_types=1);

require_once __DIR__ . '/problem.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

/**
 * Business logic, one function per operationId in api/openapi/openapi.json.
 * By the time a handler runs, the request has already passed HTTPS, rate-limit,
 * authentication and schema validation, so handlers deal only with the happy path
 * and with database outcomes.
 *
 * Each returns [HTTP status, payload array].
 */

function public_user(array $row): array
{
    return [
        'user_id'        => (int) $row['user_id'],
        'full_name'      => $row['full_name'],
        'email'          => $row['email'],
        'phone'          => $row['phone'],
        'role'           => $row['role'],
        'account_status' => $row['account_status'],
    ];
}

/** Zambian number to +260 form (the schema already guaranteed it matches). */
function to_e164_zm(string $phone): string
{
    return str_starts_with($phone, '+260') ? $phone : '+260' . substr($phone, 1);
}

function h_createUser(array $ctx): array
{
    $b = (array) $ctx['body'];                                     // already sanitised and schema-valid

    if (strlen($b['password']) > 72) {                             // bcrypt only reads 72 BYTES; maxLength counts characters
        problem(422, 'Validation failed', 'The request body does not match the API schema.', ['errors' => [[
            'field' => 'password', 'code' => 'maxBytes', 'message' => 'Password must be 72 bytes or fewer (accented characters count as more than one).']]]);
    }

    $status = $b['role'] === 'operator' ? 'pending' : 'active';    // operators wait for admin approval
    $phone  = to_e164_zm($b['phone']);

    try {
        $stmt = db()->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, role, account_status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$b['full_name'], $b['email'], $phone,
                        password_hash($b['password'], PASSWORD_DEFAULT), $b['role'], $status]);
        $id = (int) db()->lastInsertId();
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {                                // database error -> HTTP 409
            problem(409, 'Conflict', 'An account with this email already exists.', ['errors' => [[
                'field' => 'email', 'code' => 'already_registered',
                'message' => 'This email is already registered. Try logging in instead.']]]);
        }
        throw $e;
    }

    return [201, [
        'message' => $status === 'pending'
            ? 'Account created. An administrator must approve your operator profile before you can sell tickets.'
            : 'Account created. You can now log in.',
        'user' => public_user(['user_id' => $id, 'full_name' => $b['full_name'], 'email' => $b['email'],
                               'phone' => $phone, 'role' => $b['role'], 'account_status' => $status]),
    ]];
}

function h_getCurrentUser(array $ctx): array
{
    return [200, public_user($ctx['user'])];
}

function h_login(array $ctx): array
{
    $b = (array) $ctx['body'];

    $stmt = db()->prepare('SELECT user_id, full_name, email, phone, password_hash, role, account_status FROM users WHERE email = ?');
    $stmt->execute([$b['email']]);
    $row = $stmt->fetch();

    // Always run password_verify, even for an unknown email, so response time does not reveal which emails exist.
    static $dummy = '$2y$10$D2FBFp2r9DMv8RJH8JZuJeQIF2WyYdbE3lLvcznJDpkdGf4wlqpVG';   // valid bcrypt hash of a throw-away string
    $ok = password_verify($b['password'], $row['password_hash'] ?? $dummy) && $row !== false;

    if (!$ok) {
        problem(401, 'Unauthorized', 'Invalid email or password.', [], ['WWW-Authenticate: Cookie realm="zamove"']);
    }
    if ($row['account_status'] !== 'active') {                     // only revealed AFTER the password was correct
        problem(403, 'Forbidden', $row['account_status'] === 'pending'
            ? 'Your account is waiting for administrator approval.'
            : 'Your account has been suspended.');
    }

    zm_session_purge_expired();
    $expires = zm_session_start_for((int) $row['user_id']);

    return [200, [
        'message'    => 'Logged in.',
        'user'       => public_user($row),
        'expires_at' => gmdate('c', $expires),
    ]];
}

function h_logout(array $ctx): array
{
    zm_session_destroy_current();
    return [200, ['message' => 'Logged out. Your session has been revoked.']];
}

function h_listBookings(array $ctx): array
{
    $q      = $ctx['query'];
    $limit  = (int) ($q['limit'] ?? 20);
    $offset = (int) ($q['offset'] ?? 0);
    $where  = 'customer_id = ?';
    $args   = [(int) $ctx['user']['user_id']];
    if (isset($q['status'])) {
        $where .= ' AND booking_status = ?';
        $args[] = $q['status'];
    }

    $count = db()->prepare("SELECT COUNT(*) FROM bookings WHERE $where");
    $count->execute($args);
    $total = (int) $count->fetchColumn();

    $stmt = db()->prepare(
        "SELECT booking_id, booking_reference, booking_type, booking_status, total_amount, currency, created_at
           FROM bookings WHERE $where ORDER BY booking_id DESC LIMIT $limit OFFSET $offset"      // $limit/$offset are validated integers
    );
    $stmt->execute($args);
    $rows = array_map(static fn($r) => [
        'booking_id'        => (int) $r['booking_id'],
        'booking_reference' => $r['booking_reference'],
        'booking_type'      => $r['booking_type'],
        'booking_status'    => $r['booking_status'],
        'total_amount'      => (float) $r['total_amount'],
        'currency'          => $r['currency'],
        'created_at'        => $r['created_at'],
    ], $stmt->fetchAll());

    return [200, ['data' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]];
}
