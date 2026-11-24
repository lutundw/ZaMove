<?php
declare(strict_types=1);

require_once __DIR__ . '/problem.php';
require_once __DIR__ . '/db.php';

/**
 * LAB 7 (NEW FILE) - transactional booking and the schedule search.
 *
 * ACID in this file
 *   Atomicity   BEGIN ... COMMIT; any exception -> ROLLBACK, so the five writes below happen all or not at all.
 *   Consistency CHECK (seats_available >= 0), foreign keys and UNIQUE keys are enforced by the database itself.
 *   Isolation   the schedule row is locked first (SELECT ... FOR UPDATE on MySQL / InnoDB, BEGIN IMMEDIATE on SQLite),
 *               so two customers cannot both take the last seat. Other sessions never see the half-finished booking
 *               (no dirty reads): InnoDB's default REPEATABLE READ shows them only committed rows.
 *   Durability  after COMMIT the rows survive a crash (InnoDB redo log, innodb_flush_log_at_trx_commit = 1).
 *
 * The five writes (one transaction):
 *   1. lock + read the schedule        2. UPDATE schedules (take the seats)      3. INSERT bookings
 *   4. INSERT passenger_booking_details  5. INSERT payments (pending)            6. INSERT booking_status_history
 */

final class BookingFailure extends RuntimeException
{
    public function __construct(public int $status, public string $title, string $detail)
    {
        parent::__construct($detail);
    }
}

/** Test-only fault injection: lets a test crash the transaction at a named step to prove the ROLLBACK. Ignored unless ZAMOVE_ENV=test. */
function zm_fault_point(string $step): void
{
    if (getenv('ZAMOVE_ENV') !== 'test') {
        return;
    }
    $want = $_SERVER['HTTP_X_TEST_FAIL_AT'] ?? getenv('ZAMOVE_TEST_FAIL_AT') ?: '';
    if ($want === $step) {
        throw new RuntimeException("injected failure at step '$step'");
    }
}

function tx_begin(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('BEGIN IMMEDIATE');                 // take the write lock now, so the seat check cannot go stale
    } else {
        $pdo->beginTransaction();                      // MySQL: BEGIN; the row lock comes from FOR UPDATE below
    }
}

function tx_commit(PDO $pdo): void
{
    $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? $pdo->exec('COMMIT') : $pdo->commit();
}

function tx_rollback(PDO $pdo): void
{
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('ROLLBACK');
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (PDOException) {
        // nothing left to roll back (the server already ended the transaction)
    }
}

/** Reserve seats. Returns the booking summary; throws BookingFailure for expected problems. */
function create_booking_tx(PDO $pdo, int $userId, int $scheduleId, int $seats, ?string $passengerName): array
{
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $now     = gmdate('Y-m-d H:i:s');

    tx_begin($pdo);                                                                   // ---- BEGIN
    try {
        // 1. lock and read the schedule (also gives us the operator for the booking row)
        $lock = $pdo->prepare(
            'SELECT s.schedule_id, s.fare_per_seat, s.seats_available, s.schedule_status, s.departure_at, r.operator_id
               FROM schedules s JOIN routes r ON r.route_id = s.route_id
              WHERE s.schedule_id = ?' . ($isMysql ? ' FOR UPDATE' : ''));
        $lock->execute([$scheduleId]);
        $sch = $lock->fetch();
        if (!$sch || $sch['schedule_status'] !== 'scheduled' || $sch['departure_at'] <= $now) {
            throw new BookingFailure(404, 'Not Found', 'This departure does not exist or is no longer open for booking.');
        }
        if ((int) $sch['seats_available'] < $seats) {
            throw new BookingFailure(409, 'Conflict', 'Not enough seats left on this departure. Nothing was booked.');
        }

        // 2. take the seats; the WHERE clause makes the check-and-decrement a single atomic statement
        $take = $pdo->prepare('UPDATE schedules SET seats_available = seats_available - ? WHERE schedule_id = ? AND seats_available >= ?');
        $take->execute([$seats, $scheduleId, $seats]);
        if ($take->rowCount() !== 1) {
            throw new BookingFailure(409, 'Conflict', 'Not enough seats left on this departure. Nothing was booked.');
        }

        // 3. the booking (price is computed here, never taken from the client)
        $total = round((float) $sch['fare_per_seat'] * $seats, 2);
        if ($total <= 0) {
            throw new BookingFailure(409, 'Conflict', 'This departure cannot be booked online.');
        }
        $ref  = 'ZM-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $hold = gmdate('Y-m-d H:i:s', time() + 15 * 60);
        $pdo->prepare(
            "INSERT INTO bookings (booking_reference, customer_id, operator_id, booking_type, schedule_id, booking_status, total_amount, currency, hold_expires_at, created_at)
             VALUES (?, ?, ?, 'passenger', ?, 'pending', ?, 'ZMW', ?, ?)"
        )->execute([$ref, $userId, (int) $sch['operator_id'], $scheduleId, $total, $hold, $now]);
        $bookingId = (int) $pdo->lastInsertId();
        zm_fault_point('booking');

        // 4. passenger details
        $pdo->prepare('INSERT INTO passenger_booking_details (booking_id, number_of_seats, passenger_name) VALUES (?, ?, ?)')
            ->execute([$bookingId, $seats, $passengerName]);
        zm_fault_point('details');

        // 5. a pending payment record (the gateway result arrives later)
        $pdo->prepare("INSERT INTO payments (booking_id, provider, provider_reference, amount, currency, payment_status, created_at)
                       VALUES (?, 'pending-gateway', ?, ?, 'ZMW', 'pending', ?)")
            ->execute([$bookingId, 'PENDING-' . bin2hex(random_bytes(8)), $total, $now]);
        zm_fault_point('payment');

        // 6. audit trail
        $pdo->prepare("INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, changed_at) VALUES (?, NULL, 'pending', ?, ?)")
            ->execute([$bookingId, $userId, $now]);
        zm_fault_point('history');

        tx_commit($pdo);                                                              // ---- COMMIT
    } catch (Throwable $e) {
        tx_rollback($pdo);                                                            // ---- ROLLBACK: undo every step above
        throw $e;
    }

    return [
        'booking_id' => $bookingId, 'booking_reference' => $ref, 'booking_status' => 'pending',
        'schedule_id' => $scheduleId, 'seats' => $seats, 'total_amount' => $total, 'currency' => 'ZMW',
        'hold_expires_at' => $hold, 'payment_status' => 'pending',
    ];
}

function h_createBooking(array $ctx): array
{
    if (($ctx['user']['role'] ?? '') !== 'customer') {                                 // REAL-APP: only passengers buy tickets
        problem(403, 'Forbidden', 'Only passenger accounts can book seats.');
    }
    require_once __DIR__ . '/marketplace.php';
    zm_release_expired_holds(db());                                                    // REAL-APP: seats of expired holds go back on sale first
    $b = (array) $ctx['body'];
    try {
        $booking = create_booking_tx(db(), (int) $ctx['user']['user_id'], (int) $b['schedule_id'], (int) $b['seats'],
                                     isset($b['passenger_name']) && $b['passenger_name'] !== '' ? $b['passenger_name'] : null);
    } catch (BookingFailure $e) {
        problem($e->status, $e->title, $e->getMessage());                              // expected outcome, already rolled back
    }
    // anything else is an unexpected failure: the transaction was rolled back, index.php turns it into a generic 500
    // ---- LAB 8: tell the customer and the operator in real time (event is stored; the WebSocket daemon pushes it) ----
    require_once __DIR__ . '/realtime.php';
    rt_emit(db(), (int) $booking['booking_id'], 'booking.created', [
        'booking_id' => (int) $booking['booking_id'], 'booking_reference' => $booking['booking_reference'],
        'seats' => $booking['seats'], 'total_amount' => $booking['total_amount'], 'currency' => $booking['currency'],
    ]);
    // ---- end LAB 8 ----
    return [201, ['message' => 'Seats reserved. Pay within 15 minutes to confirm the booking.', 'booking' => $booking]];
}

// REMOVED: h_searchSchedules moved to api/lib/marketplace.php (richer rows, operator name, expired holds released).
