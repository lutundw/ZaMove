<?php
declare(strict_types=1);

require_once __DIR__ . '/problem.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/authz.php';
require_once __DIR__ . '/realtime.php';
require_once __DIR__ . '/bookings_tx.php';

/**
 * REAL-APP (NEW FILE) - the marketplace: operator company profile, vehicles, routes, departures,
 * admin approval, trip lookup, demo payment, and the hold-expiry sweeper.
 *
 * Every function is named h_<operationId> (see api/openapi/openapi.json) and returns [status, payload].
 * All timestamps are stored in UTC and returned as ISO-8601 with a trailing "Z"; the browser shows them in
 * Central Africa Time (UTC+2).
 */

/** 'Y-m-d H:i:s' (UTC, as stored) -> '2026-11-24T05:00:00Z'. */
function zm_iso(?string $utc): ?string
{
    return $utc === null || $utc === '' ? null : str_replace(' ', 'T', $utc) . 'Z';
}

/** Any ISO-8601 instant (with offset) -> 'Y-m-d H:i:s' in UTC. */
function zm_to_utc(string $iso): string
{
    return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Title-case a town name the way it is stored ("lusaka" / "LUSAKA " -> "Lusaka"). */
function zm_town(string $name): string
{
    return mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');
}

/** The signed-in OPERATOR's company id; 403 for other roles and for companies that are not approved yet. */
function zm_require_approved_operator(array $user): int
{
    if (($user['role'] ?? '') !== 'operator') {
        problem(403, 'Forbidden', 'Only bus operator accounts can use this endpoint.');
    }
    $st = db()->prepare('SELECT operator_id, verification_status FROM operators WHERE user_id = ?');
    $st->execute([(int) $user['user_id']]);
    $row = $st->fetch();
    if (!$row) {
        problem(403, 'Forbidden', 'Your account has no company profile. Contact support.');
    }
    if ($row['verification_status'] !== 'approved') {
        problem(403, 'Forbidden', $row['verification_status'] === 'pending'
            ? 'Your company is waiting for administrator approval.'
            : 'Your company application was not approved.');
    }
    return (int) $row['operator_id'];
}

/* ------------------------------------------------------------------------------------------------
 * Hold expiry: a pending booking keeps its seats for 15 minutes. After that the seats go back on sale.
 * Called before every search, booking and payment, so no cron job is needed for a class project.
 * ---------------------------------------------------------------------------------------------- */
function zm_release_expired_holds(PDO $pdo): int
{
    $now  = gmdate('Y-m-d H:i:s');
    $st   = $pdo->prepare("SELECT b.booking_id, b.schedule_id, d.number_of_seats AS seats
                             FROM bookings b JOIN passenger_booking_details d ON d.booking_id = b.booking_id
                            WHERE b.booking_status = 'pending' AND b.booking_type = 'passenger'
                              AND b.hold_expires_at IS NOT NULL AND b.hold_expires_at < ? LIMIT 50");
    $st->execute([$now]);
    $released = 0;
    foreach ($st->fetchAll() as $r) {
        tx_begin($pdo);
        try {
            // the status guard makes this safe if two requests sweep at once: only one of them wins the UPDATE
            $u = $pdo->prepare("UPDATE bookings SET booking_status = 'cancelled' WHERE booking_id = ? AND booking_status = 'pending'");
            $u->execute([(int) $r['booking_id']]);
            if ($u->rowCount() === 1) {
                $pdo->prepare('UPDATE schedules SET seats_available = seats_available + ? WHERE schedule_id = ?')
                    ->execute([(int) $r['seats'], (int) $r['schedule_id']]);
                $pdo->prepare("INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, changed_at) VALUES (?, 'pending', 'cancelled', NULL, ?)")
                    ->execute([(int) $r['booking_id'], $now]);
                $pdo->prepare("UPDATE payments SET payment_status = 'cancelled' WHERE booking_id = ? AND payment_status = 'pending'")
                    ->execute([(int) $r['booking_id']]);
                $released++;
            }
            tx_commit($pdo);
        } catch (Throwable $e) {
            tx_rollback($pdo);
            error_log('[zamove] hold sweep failed: ' . $e->getMessage());
        }
    }
    return $released;
}

/* ------------------------------------------------------------------------------------------------
 * Public: places and trips
 * ---------------------------------------------------------------------------------------------- */

function h_listPlaces(array $ctx): array
{
    $now = gmdate('Y-m-d H:i:s');
    $st  = db()->prepare("SELECT DISTINCT r.origin AS town FROM routes r JOIN schedules s ON s.route_id = r.route_id
                           WHERE r.route_status = 'active' AND s.schedule_status = 'scheduled' AND s.departure_at > ?
                          UNION
                          SELECT DISTINCT r.destination FROM routes r JOIN schedules s ON s.route_id = r.route_id
                           WHERE r.route_status = 'active' AND s.schedule_status = 'scheduled' AND s.departure_at > ?
                          ORDER BY 1");
    $st->execute([$now, $now]);
    return [200, ['data' => array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))]];
}

const ZM_TRIP_SELECT =
    'SELECT s.schedule_id, r.origin, r.destination, s.departure_at, s.arrival_at, s.fare_per_seat, s.seats_available, s.total_seats,
            o.business_name AS operator_name, v.vehicle_type
       FROM schedules s
       JOIN routes r    ON r.route_id = s.route_id
       JOIN operators o ON o.operator_id = r.operator_id
       JOIN vehicles v  ON v.vehicle_id = s.vehicle_id';

function zm_trip_row(array $r): array
{
    return [
        'schedule_id'    => (int) $r['schedule_id'],
        'origin'         => $r['origin'],
        'destination'    => $r['destination'],
        'departure_at'   => zm_iso($r['departure_at']),
        'arrival_at'     => zm_iso($r['arrival_at']),
        'fare_per_seat'  => (float) $r['fare_per_seat'],
        'seats_available' => (int) $r['seats_available'],
        'total_seats'    => (int) $r['total_seats'],
        'operator_name'  => $r['operator_name'],
        'vehicle_type'   => $r['vehicle_type'],
    ];
}

/** GET /schedules : public search. Replaces the version in bookings_tx.php (richer rows, expired holds released first). */
function h_searchSchedules(array $ctx): array
{
    zm_release_expired_holds(db());
    $q      = $ctx['query'];
    $limit  = (int) ($q['limit'] ?? 20);
    $offset = (int) ($q['offset'] ?? 0);
    $where  = ["s.schedule_status = 'scheduled'", 's.departure_at > ?', 's.seats_available > 0', "r.route_status = 'active'", "o.verification_status = 'approved'"];
    $args   = [gmdate('Y-m-d H:i:s')];
    if (isset($q['origin']))      { $where[] = 'r.origin = ?';      $args[] = zm_town($q['origin']); }
    if (isset($q['destination'])) { $where[] = 'r.destination = ?'; $args[] = zm_town($q['destination']); }
    if (isset($q['date'])) {
        [$y, $m, $d] = array_map('intval', explode('-', $q['date']));
        if (!checkdate($m, $d, $y)) {
            problem(400, 'Bad Request', "Query parameter 'date' is not a real calendar date.");
        }
        // The traveller means a day in Zambia (UTC+2): 00:00 CAT = 22:00 UTC the evening before.
        $startUtc = gmdate('Y-m-d H:i:s', strtotime($q['date'] . ' 00:00:00 UTC') - 2 * 3600);
        $where[]  = 's.departure_at >= ? AND s.departure_at < ?';
        $args[]   = $startUtc;
        $args[]   = gmdate('Y-m-d H:i:s', strtotime($startUtc . ' UTC') + 86400);
    }
    $stmt = db()->prepare(ZM_TRIP_SELECT . ' WHERE ' . implode(' AND ', $where) . "
          ORDER BY s.departure_at ASC, s.schedule_id ASC LIMIT $limit OFFSET $offset");
    $stmt->execute($args);
    return [200, ['data' => array_map('zm_trip_row', $stmt->fetchAll()), 'limit' => $limit, 'offset' => $offset]];
}

/** GET /schedules/{id} : one departure (public). */
function h_getSchedule(array $ctx): array
{
    zm_release_expired_holds(db());
    $st = db()->prepare(ZM_TRIP_SELECT . " WHERE s.schedule_id = ? AND s.schedule_status = 'scheduled' AND o.verification_status = 'approved'");
    $st->execute([(int) $ctx['params']['id']]);
    $r = $st->fetch();
    if (!$r || $r['departure_at'] <= gmdate('Y-m-d H:i:s')) {
        problem(404, 'Not Found', 'This departure does not exist or has already left.');
    }
    return [200, zm_trip_row($r)];
}

/* ------------------------------------------------------------------------------------------------
 * Operator: vehicles, routes, departures, bookings
 * ---------------------------------------------------------------------------------------------- */

function h_listMyVehicles(array $ctx): array
{
    $op = zm_require_approved_operator($ctx['user']);
    $st = db()->prepare('SELECT vehicle_id, registration_number, vehicle_type, seat_capacity, vehicle_status FROM vehicles WHERE operator_id = ? ORDER BY vehicle_id');
    $st->execute([$op]);
    return [200, ['data' => array_map(static fn($r) => [
        'vehicle_id' => (int) $r['vehicle_id'], 'registration_number' => $r['registration_number'],
        'vehicle_type' => $r['vehicle_type'], 'seat_capacity' => (int) $r['seat_capacity'], 'vehicle_status' => $r['vehicle_status'],
    ], $st->fetchAll())]];
}

function h_createVehicle(array $ctx): array
{
    $op = zm_require_approved_operator($ctx['user']);
    $b  = (array) $ctx['body'];
    $reg = strtoupper($b['registration_number']);
    try {
        db()->prepare("INSERT INTO vehicles (operator_id, registration_number, vehicle_type, seat_capacity, vehicle_status) VALUES (?, ?, ?, ?, 'active')")
            ->execute([$op, $reg, $b['vehicle_type'], (int) $b['seat_capacity']]);
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            problem(409, 'Conflict', 'A vehicle with this registration number already exists.', ['errors' => [[
                'field' => 'registration_number', 'code' => 'already_registered', 'message' => 'This registration number is already on the platform.']]]);
        }
        throw $e;
    }
    return [201, ['message' => 'Vehicle added.', 'vehicle_id' => (int) db()->lastInsertId()]];
}

function h_listMyRoutes(array $ctx): array
{
    $op = zm_require_approved_operator($ctx['user']);
    $st = db()->prepare('SELECT route_id, origin, destination, distance_km, base_fare, route_status FROM routes WHERE operator_id = ? ORDER BY origin, destination');
    $st->execute([$op]);
    return [200, ['data' => array_map(static fn($r) => [
        'route_id' => (int) $r['route_id'], 'origin' => $r['origin'], 'destination' => $r['destination'],
        'distance_km' => $r['distance_km'] === null ? null : (float) $r['distance_km'],
        'base_fare' => (float) $r['base_fare'], 'route_status' => $r['route_status'],
    ], $st->fetchAll())]];
}

function h_createRoute(array $ctx): array
{
    $op = zm_require_approved_operator($ctx['user']);
    $b  = (array) $ctx['body'];
    $from = zm_town($b['origin']);
    $to   = zm_town($b['destination']);
    if (mb_strtolower($from) === mb_strtolower($to)) {
        problem(422, 'Validation failed', 'The request body does not match the API schema.', ['errors' => [[
            'field' => 'destination', 'code' => 'same_as_origin', 'message' => 'Destination must be different from the origin.']]]);
    }
    $dup = db()->prepare('SELECT route_id FROM routes WHERE operator_id = ? AND origin = ? AND destination = ?');
    $dup->execute([$op, $from, $to]);
    if ($dup->fetchColumn() !== false) {
        problem(409, 'Conflict', 'You already have this route.', ['errors' => [[
            'field' => 'destination', 'code' => 'already_exists', 'message' => 'You already run ' . $from . ' to ' . $to . '. Add a departure to it instead.']]]);
    }
    db()->prepare("INSERT INTO routes (operator_id, origin, destination, distance_km, base_fare, route_status) VALUES (?, ?, ?, ?, ?, 'active')")
        ->execute([$op, $from, $to, isset($b['distance_km']) ? (float) $b['distance_km'] : null, (float) $b['base_fare']]);
    return [201, ['message' => 'Route added.', 'route_id' => (int) db()->lastInsertId()]];
}

/** POST /schedules : an operator opens a departure. (Replaces the earlier version in handlers.php, which used column names that do not exist.) */
function h_createSchedule(array $ctx): array
{
    $op = zm_require_approved_operator($ctx['user']);
    $b  = (array) $ctx['body'];
    $bad = static fn(string $field, string $code, string $msg) => problem(422, 'Validation failed',
        'The request body does not match the API schema.', ['errors' => [['field' => $field, 'code' => $code, 'message' => $msg]]]);

    // the route must be this operator's own, and open
    $st = db()->prepare("SELECT route_id FROM routes WHERE route_id = ? AND operator_id = ? AND route_status = 'active'");
    $st->execute([(int) $b['route_id'], $op]);
    if ($st->fetchColumn() === false) {
        problem(404, 'Not Found', 'No active route with that id was found in your company.');
    }
    // so must the vehicle
    $st = db()->prepare("SELECT seat_capacity FROM vehicles WHERE vehicle_id = ? AND operator_id = ? AND vehicle_status = 'active'");
    $st->execute([(int) $b['vehicle_id'], $op]);
    $capacity = $st->fetchColumn();
    if ($capacity === false) {
        problem(404, 'Not Found', 'No active vehicle with that id was found in your company.');
    }
    if ((int) $b['total_seats'] > (int) $capacity) {
        $bad('total_seats', 'exceeds_capacity', 'This vehicle has only ' . (int) $capacity . ' seats.');
    }

    try {
        $dep = zm_to_utc($b['departure_time']);
        $arr = zm_to_utc($b['arrival_time']);
    } catch (Throwable) {
        $bad('departure_time', 'format', 'Use a full date and time, for example 2026-12-01T07:00:00+02:00.');
    }
    if ($dep <= gmdate('Y-m-d H:i:s', time() + 30 * 60)) {
        $bad('departure_time', 'too_soon', 'A departure must be at least 30 minutes from now.');
    }
    if ($arr <= $dep) {
        $bad('arrival_time', 'before_departure', 'Arrival must be after departure.');
    }
    if (strtotime($arr . ' UTC') - strtotime($dep . ' UTC') > 48 * 3600) {
        $bad('arrival_time', 'too_long', 'A trip cannot last more than 48 hours.');
    }
    // the same bus cannot be on two overlapping trips
    $ov = db()->prepare("SELECT schedule_id FROM schedules WHERE vehicle_id = ? AND schedule_status = 'scheduled'
                            AND departure_at < ? AND COALESCE(arrival_at, departure_at) > ? LIMIT 1");
    $ov->execute([(int) $b['vehicle_id'], $arr, $dep]);
    if ($ov->fetchColumn() !== false) {
        problem(409, 'Conflict', 'This vehicle is already on another trip at that time.');
    }

    db()->prepare("INSERT INTO schedules (route_id, vehicle_id, departure_at, arrival_at, fare_per_seat, total_seats, schedule_status, seats_available)
                   VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?)")
        ->execute([(int) $b['route_id'], (int) $b['vehicle_id'], $dep, $arr, round((float) $b['fare_per_seat'], 2),
                   (int) $b['total_seats'], (int) $b['total_seats']]);          // every seat starts on sale
    return [201, ['message' => 'Departure created.', 'schedule_id' => (int) db()->lastInsertId()]];
}

function h_listMySchedules(array $ctx): array
{
    $op = zm_require_approved_operator($ctx['user']);
    $st = db()->prepare("SELECT s.schedule_id, r.origin, r.destination, s.departure_at, s.arrival_at, s.fare_per_seat, s.total_seats,
                                s.seats_available, s.schedule_status, v.registration_number
                           FROM schedules s JOIN routes r ON r.route_id = s.route_id JOIN vehicles v ON v.vehicle_id = s.vehicle_id
                          WHERE r.operator_id = ? ORDER BY s.departure_at DESC, s.schedule_id DESC LIMIT 100");
    $st->execute([$op]);
    return [200, ['data' => array_map(static fn($r) => [
        'schedule_id' => (int) $r['schedule_id'], 'origin' => $r['origin'], 'destination' => $r['destination'],
        'departure_at' => zm_iso($r['departure_at']), 'arrival_at' => zm_iso($r['arrival_at']),
        'fare_per_seat' => (float) $r['fare_per_seat'], 'total_seats' => (int) $r['total_seats'],
        'seats_available' => (int) $r['seats_available'], 'schedule_status' => $r['schedule_status'],
        'registration_number' => $r['registration_number'],
    ], $st->fetchAll())]];
}

/** GET /operator/bookings : bookings on this company's trips, with the passenger and payment state. */
function h_listOperatorBookings(array $ctx): array
{
    $op = zm_require_approved_operator($ctx['user']);
    zm_release_expired_holds(db());
    $st = db()->prepare("SELECT b.booking_id, b.booking_reference, b.booking_status, b.total_amount, b.currency, b.created_at,
                                r.origin, r.destination, s.departure_at, d.number_of_seats, d.passenger_name,
                                u.full_name AS customer_name, u.phone AS customer_phone,
                                (SELECT p.payment_status FROM payments p WHERE p.booking_id = b.booking_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_status
                           FROM bookings b
                           JOIN schedules s ON s.schedule_id = b.schedule_id
                           JOIN routes r    ON r.route_id = s.route_id
                           JOIN users u     ON u.user_id = b.customer_id
                           LEFT JOIN passenger_booking_details d ON d.booking_id = b.booking_id
                          WHERE b.operator_id = ? AND b.booking_type = 'passenger'
                          ORDER BY b.created_at DESC, b.booking_id DESC LIMIT 100");
    $st->execute([$op]);
    return [200, ['data' => array_map(static fn($r) => [
        'booking_id' => (int) $r['booking_id'], 'booking_reference' => $r['booking_reference'], 'booking_status' => $r['booking_status'],
        'total_amount' => (float) $r['total_amount'], 'currency' => $r['currency'], 'created_at' => $r['created_at'],
        'origin' => $r['origin'], 'destination' => $r['destination'], 'departure_at' => zm_iso($r['departure_at']),
        'seats' => (int) $r['number_of_seats'], 'passenger_name' => $r['passenger_name'],
        'customer_name' => $r['customer_name'], 'customer_phone' => $r['customer_phone'], 'payment_status' => $r['payment_status'],
    ], $st->fetchAll())]];
}

/* ------------------------------------------------------------------------------------------------
 * Admin: approving operator companies
 * ---------------------------------------------------------------------------------------------- */

function h_listOperators(array $ctx): array
{
    require_role($ctx['user'], ['admin']);
    $st = db()->query("SELECT o.operator_id, o.business_name, o.business_type, o.business_address, o.verification_status,
                              u.full_name AS owner_name, u.email AS owner_email, u.phone AS owner_phone, u.created_at
                         FROM operators o JOIN users u ON u.user_id = o.user_id
                        ORDER BY CASE o.verification_status WHEN 'pending' THEN 0 ELSE 1 END, o.operator_id DESC LIMIT 200");
    return [200, ['data' => array_map(static fn($r) => [
        'operator_id' => (int) $r['operator_id'], 'business_name' => $r['business_name'], 'business_type' => $r['business_type'],
        'business_address' => $r['business_address'], 'verification_status' => $r['verification_status'],
        'owner_name' => $r['owner_name'], 'owner_email' => $r['owner_email'], 'owner_phone' => $r['owner_phone'],
    ], $st->fetchAll())]];
}

function h_decideOperator(array $ctx): array
{
    require_role($ctx['user'], ['admin']);
    $id       = (int) $ctx['params']['id'];
    $decision = ((array) $ctx['body'])['decision'];
    $newOp    = $decision === 'approve' ? 'approved' : 'rejected';
    $newUser  = $decision === 'approve' ? 'active' : 'suspended';           // login requires an 'active' account
    $pdo = db();
    tx_begin($pdo);
    try {
        $st = $pdo->prepare('SELECT user_id FROM operators WHERE operator_id = ?');
        $st->execute([$id]);
        $uid = $st->fetchColumn();
        if ($uid === false) {
            tx_rollback($pdo);
            problem(404, 'Not Found', 'No operator company with that id.');
        }
        $pdo->prepare('UPDATE operators SET verification_status = ?, approved_by = ? WHERE operator_id = ?')
            ->execute([$newOp, (int) $ctx['user']['user_id'], $id]);
        $pdo->prepare('UPDATE users SET account_status = ? WHERE user_id = ?')->execute([$newUser, (int) $uid]);
        tx_commit($pdo);
    } catch (Throwable $e) {
        tx_rollback($pdo);
        throw $e;
    }
    security_log('operator_decision', ['operator_id' => $id, 'decision' => $newOp, 'by' => (int) $ctx['user']['user_id']]);
    return [200, ['message' => 'Company ' . $newOp . '.', 'operator_id' => $id, 'verification_status' => $newOp]];
}

/* ------------------------------------------------------------------------------------------------
 * Customer: pay for a held booking (DEMONSTRATION gateway - no money moves)
 * ---------------------------------------------------------------------------------------------- */

function h_payBooking(array $ctx): array
{
    $pdo = db();
    zm_release_expired_holds($pdo);
    $id  = (int) $ctx['params']['id'];
    $b   = (array) $ctx['body'];
    $uid = (int) $ctx['user']['user_id'];
    $now = gmdate('Y-m-d H:i:s');

    tx_begin($pdo);
    try {
        $st = $pdo->prepare("SELECT booking_id, customer_id, booking_status, total_amount, hold_expires_at FROM bookings WHERE booking_id = ?" .
                            ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        $bk = $st->fetch();
        if (!$bk || (int) $bk['customer_id'] !== $uid) {                      // same 404 for "not yours" and "does not exist"
            tx_rollback($pdo);
            problem(404, 'Not Found', 'No booking with that id was found for your account.');
        }
        if ($bk['booking_status'] === 'confirmed') {
            tx_rollback($pdo);
            problem(409, 'Conflict', 'This booking is already paid.');
        }
        if ($bk['booking_status'] !== 'pending' || ($bk['hold_expires_at'] !== null && $bk['hold_expires_at'] < $now)) {
            tx_rollback($pdo);
            problem(409, 'Conflict', 'The 15-minute hold on these seats has ended. Please search again.');
        }

        $failed = !empty($b['simulate_failure']);
        // reuse the open payment row (created with the booking), so a retry never creates duplicates
        $pay = $pdo->prepare("SELECT payment_id FROM payments WHERE booking_id = ? AND payment_status IN ('pending','failed') ORDER BY payment_id DESC LIMIT 1");
        $pay->execute([$id]);
        $pid = $pay->fetchColumn();
        $ref = 'DEMO-' . strtoupper(bin2hex(random_bytes(6)));
        if ($pid === false) {
            $pdo->prepare('INSERT INTO payments (booking_id, provider, provider_reference, amount, currency, payment_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, 'demo-' . $b['method'], $ref, $bk['total_amount'], 'ZMW', $failed ? 'failed' : 'successful', $now]);
        } else {
            $pdo->prepare('UPDATE payments SET provider = ?, provider_reference = ?, payment_status = ?, paid_at = ? WHERE payment_id = ?')
                ->execute(['demo-' . $b['method'], $ref, $failed ? 'failed' : 'successful', $failed ? null : $now, (int) $pid]);
        }
        if (!$failed) {
            $pdo->prepare("UPDATE bookings SET booking_status = 'confirmed', hold_expires_at = NULL WHERE booking_id = ?")->execute([$id]);
            $pdo->prepare("INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, changed_at) VALUES (?, 'pending', 'confirmed', ?, ?)")
                ->execute([$id, $uid, $now]);
        }
        tx_commit($pdo);
    } catch (Throwable $e) {
        tx_rollback($pdo);
        throw $e;
    }

    if ($failed) {
        problem(402, 'Payment Required', 'The demo payment was declined. Your seats are still held, so you can try again.');
    }
    rt_emit($pdo, $id, 'booking.status', ['booking_id' => $id, 'old_status' => 'pending', 'new_status' => 'confirmed', 'by' => $ctx['user']['full_name']]);
    return [200, ['message' => 'Payment received (demonstration gateway: no real money was charged).', 'booking_id' => $id,
                  'booking_status' => 'confirmed', 'payment_status' => 'successful', 'demo' => true]];
}
