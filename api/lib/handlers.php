<?php
declare(strict_types=1);

require_once __DIR__ . '/problem.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/authz.php';

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

    if ($b['role'] === 'operator') {                               // an operator account always comes with a company profile
        $missing = [];
        foreach (['business_name' => 'Enter your company name.', 'business_address' => 'Enter your company address.'] as $f => $msg) {
            if (!isset($b[$f]) || trim((string) $b[$f]) === '') {
                $missing[] = ['field' => $f, 'code' => 'required', 'message' => $msg];
            }
        }
        if ($missing) {
            problem(422, 'Validation failed', 'The request body does not match the API schema.', ['errors' => $missing]);
        }
    }

    $pdo = db();
    $pdo->beginTransaction();                                      // user + company profile are created together or not at all
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, role, account_status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$b['full_name'], $b['email'], $phone,
                        password_hash($b['password'], PASSWORD_DEFAULT), $b['role'], $status]);
        $id = (int) $pdo->lastInsertId();
        if ($b['role'] === 'operator') {
            $pdo->prepare("INSERT INTO operators (user_id, business_name, business_type, business_address, verification_status) VALUES (?, ?, ?, ?, 'pending')")
                ->execute([$id, $b['business_name'], $b['business_type'] ?? 'bus', $b['business_address']]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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

/** Shared SELECT for booking lists and detail: the booking plus its trip, seats and latest payment. */
const BOOKING_SELECT =
    'SELECT b.booking_id, b.booking_reference, b.booking_type, b.booking_status, b.total_amount, b.currency, b.created_at, b.hold_expires_at,
            b.customer_id, b.operator_id,
            r.origin, r.destination, s.departure_at, s.arrival_at, o.business_name AS operator_name,
            d.number_of_seats, d.passenger_name,
            (SELECT p.payment_status FROM payments p WHERE p.booking_id = b.booking_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_status
       FROM bookings b
       LEFT JOIN schedules s ON s.schedule_id = b.schedule_id
       LEFT JOIN routes r    ON r.route_id = s.route_id
       LEFT JOIN operators o ON o.operator_id = b.operator_id
       LEFT JOIN passenger_booking_details d ON d.booking_id = b.booking_id';

function booking_row(array $r): array
{
    $out = [
        'booking_id'        => (int) $r['booking_id'],
        'booking_reference' => $r['booking_reference'],
        'booking_type'      => $r['booking_type'],
        'booking_status'    => $r['booking_status'],
        'total_amount'      => (float) $r['total_amount'],
        'currency'          => $r['currency'],
        'created_at'        => $r['created_at'],
    ];
    if ($r['origin'] !== null) {                                                        // passenger booking: add the trip
        $out += [
            'origin' => $r['origin'], 'destination' => $r['destination'],
            'departure_at' => str_replace(' ', 'T', (string) $r['departure_at']) . 'Z',
            'arrival_at'   => $r['arrival_at'] === null ? null : str_replace(' ', 'T', (string) $r['arrival_at']) . 'Z',
            'operator_name' => $r['operator_name'], 'seats' => (int) $r['number_of_seats'],
            'passenger_name' => $r['passenger_name'], 'payment_status' => $r['payment_status'],
            'hold_expires_at' => $r['hold_expires_at'] === null ? null : str_replace(' ', 'T', (string) $r['hold_expires_at']) . 'Z',
        ];
    }
    return $out;
}

function h_listBookings(array $ctx): array
{
    $q      = $ctx['query'];
    $limit  = (int) ($q['limit'] ?? 20);
    $offset = (int) ($q['offset'] ?? 0);
    $where  = 'b.customer_id = ?';
    $args   = [(int) $ctx['user']['user_id']];
    if (isset($q['status'])) {
        $where .= ' AND b.booking_status = ?';
        $args[] = $q['status'];
    }

    $count = db()->prepare("SELECT COUNT(*) FROM bookings b WHERE $where");
    $count->execute($args);
    $total = (int) $count->fetchColumn();

    // Lab 6: LIMIT and OFFSET are bound parameters too (before, validated integers were pasted into the SQL text).
    $stmt = db()->prepare(
        BOOKING_SELECT . " WHERE $where ORDER BY b.created_at DESC, b.booking_id DESC LIMIT ? OFFSET ?"       // $where is built from constants only
    );
    foreach ($args as $i => $value) {
        $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(count($args) + 1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(count($args) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_map('booking_row', $stmt->fetchAll());

    return [200, ['data' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]];
}

/** GET /bookings/{id}: one booking, only if the signed-in user is allowed to see it (Lab 6, A01). */
function h_getBooking(array $ctx): array
{
    $id   = (int) $ctx['params']['id'];
    $stmt = db()->prepare(BOOKING_SELECT . ' WHERE b.booking_id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();

    // Same 404 whether the booking does not exist or belongs to somebody else, so ids cannot be probed.
    if (!$r || !authz_can_view_booking($ctx['user'], $r)) {
        if ($r) {
            security_log('authz_denied', ['user_id' => (int) $ctx['user']['user_id'], 'role' => $ctx['user']['role'], 'booking_id' => $id]);
        }
        problem(404, 'Not Found', 'No booking with that id was found for your account.');
    }
    return [200, booking_row($r)];
}

// REMOVED: h_createSchedule moved to api/lib/marketplace.php (it used columns that do not exist: departure_time, arrival_time, status).
