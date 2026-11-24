<?php
declare(strict_types=1);

/**
 * LAB 8 (NEW FILE) - shared real-time helpers used by BOTH the HTTP API and the WebSocket daemon.
 *
 *   rt_emit()      persist an event for every participant of a booking (parameterised INSERT)
 *   rt_replay()    events a user missed (event_id > cursor)
 *   rt_new_events() events created since the daemon last looked (used by the broadcast loop)
 *
 * The HTTP side never talks to the daemon directly: it only INSERTs rows. The daemon polls the table.
 * That keeps the two processes decoupled (no shared memory, no extra port) and means an event is never
 * lost when the daemon is down: it is simply delivered on the next reconnect.
 */
require_once __DIR__ . '/db.php';

const RT_STATUSES = ['accepted', 'rejected', 'confirmed', 'in_transit', 'completed', 'cancelled'];

/** user_ids that take part in a booking: the customer and the operator company's account. */
function rt_participants(PDO $pdo, int $bookingId): array
{
    $st = $pdo->prepare('SELECT b.customer_id, o.user_id AS operator_user FROM bookings b
                           LEFT JOIN operators o ON o.operator_id = b.operator_id WHERE b.booking_id = ?');
    $st->execute([$bookingId]);
    $r = $st->fetch();
    if (!$r) {
        return [];
    }
    return array_values(array_unique(array_filter([(int) $r['customer_id'], (int) ($r['operator_user'] ?? 0)])));
}

/** Persist one event per participant. Returns the number of rows written. Never throws into the caller's request. */
function rt_emit(PDO $pdo, int $bookingId, string $type, array $payload): int
{
    try {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 4096) {
            return 0;
        }
        $ins = $pdo->prepare('INSERT INTO realtime_events (user_id, booking_id, event_type, payload, created_at) VALUES (?, ?, ?, ?, ?)');
        $n = 0;
        foreach (rt_participants($pdo, $bookingId) as $uid) {
            $ins->execute([$uid, $bookingId, $type, $json, gmdate('Y-m-d H:i:s')]);
            $n++;
        }
        return $n;
    } catch (Throwable $e) {
        error_log('rt_emit failed: ' . $e->getMessage());      // a notification problem must never fail a booking
        return 0;
    }
}

function rt_row_to_event(array $r, bool $replay = false): array
{
    return [
        'type'       => 'event',
        'event_id'   => (int) $r['event_id'],
        'event_type' => $r['event_type'],
        'booking_id' => $r['booking_id'] === null ? null : (int) $r['booking_id'],
        'payload'    => json_decode((string) $r['payload'], true) ?: new stdClass(),
        'created_at' => $r['created_at'],
        'replay'     => $replay,
    ];
}

/** What a user missed: everything after $afterId, oldest first, capped so one reconnect cannot flood the socket. */
function rt_replay(PDO $pdo, int $userId, int $afterId, int $limit = 200): array
{
    $st = $pdo->prepare('SELECT event_id, booking_id, event_type, payload, created_at FROM realtime_events
                          WHERE user_id = ? AND event_id > ? ORDER BY event_id ASC LIMIT ' . (int) $limit);
    $st->execute([$userId, $afterId]);
    return array_map(fn($r) => rt_row_to_event($r, true), $st->fetchAll());
}

function rt_max_event_id(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COALESCE(MAX(event_id), 0) FROM realtime_events')->fetchColumn();
}

/** Rows written since the daemon's cursor, with their recipient (for fan-out to that user's sockets). */
function rt_new_events(PDO $pdo, int $afterId, int $limit = 500): array
{
    $st = $pdo->prepare('SELECT event_id, user_id, booking_id, event_type, payload, created_at FROM realtime_events
                          WHERE event_id > ? ORDER BY event_id ASC LIMIT ' . (int) $limit);
    $st->execute([$afterId]);
    return $st->fetchAll();
}

/** Operator/admin changes a booking status: update + audit row + events, all in one transaction. */
function rt_change_status(PDO $pdo, array $user, int $bookingId, string $newStatus): array
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT booking_id, customer_id, operator_id, booking_status FROM bookings WHERE booking_id = ?');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b || !authz_can_view_booking($user, $b)) {
            $pdo->rollBack();
            return ['ok' => false, 'detail' => 'No booking with that id was found for your account.'];
        }
        if ($b['booking_status'] === $newStatus) {
            $pdo->rollBack();
            return ['ok' => false, 'detail' => 'The booking already has that status.'];
        }
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare('UPDATE bookings SET booking_status = ? WHERE booking_id = ?')->execute([$newStatus, $bookingId]);
        $pdo->prepare('INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$bookingId, $b['booking_status'], $newStatus, (int) $user['user_id'], $now]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    rt_emit($pdo, $bookingId, 'booking.status', ['booking_id' => $bookingId, 'old_status' => $b['booking_status'], 'new_status' => $newStatus, 'by' => $user['full_name']]);
    return ['ok' => true];
}

/** Chat-style message on a booking, delivered to every participant (and kept for those offline). */
function rt_send_message(PDO $pdo, array $user, int $bookingId, string $text): array
{
    $st = $pdo->prepare('SELECT booking_id, customer_id, operator_id FROM bookings WHERE booking_id = ?');
    $st->execute([$bookingId]);
    $b = $st->fetch();
    if (!$b || !authz_can_view_booking($user, $b)) {
        return ['ok' => false, 'detail' => 'No booking with that id was found for your account.'];
    }
    $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));   // strip control characters
    if ($text === '') {
        return ['ok' => false, 'detail' => 'Message is empty.'];
    }
    rt_emit($pdo, $bookingId, 'booking.message', ['booking_id' => $bookingId, 'from' => $user['full_name'], 'from_id' => (int) $user['user_id'], 'text' => $text]);
    return ['ok' => true];
}
