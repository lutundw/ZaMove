<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Lab 6, Task 1.3 / A01: object-level authorisation (RBAC + ownership).
 * Authentication (require_auth) answers "who are you?"; these functions answer
 * "may YOU touch THIS record?". Every route that takes an object id must call one of them.
 */

/** Append one line to api/logs/security.log (A09). Values are encoded so they cannot forge log lines. */
function security_log(string $event, array $context = []): void
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = gmdate('c') . ' ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\n";
    @file_put_contents($dir . '/security.log', $line, FILE_APPEND | LOCK_EX);
}

/** operator_id of the company a signed-in operator account belongs to, or null (also for unapproved operators). */
function authz_operator_id(array $user): ?int
{
    $stmt = db()->prepare("SELECT operator_id FROM operators WHERE user_id = ? AND verification_status = 'approved'");
    $stmt->execute([(int) $user['user_id']]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Role matrix for a booking row (must contain customer_id and operator_id):
 *   admin     any booking
 *   operator  bookings of its own approved company only
 *   customer  its own bookings only
 */
function authz_can_view_booking(array $user, array $booking): bool
{
    switch ($user['role'] ?? '') {
        case 'admin':
            return true;
        case 'operator':
            $op = authz_operator_id($user);
            return $op !== null && $op === (int) $booking['operator_id'];
        case 'customer':
            return (int) $booking['customer_id'] === (int) $user['user_id'];
        default:
            return false;                                       // unknown role: deny by default
    }
}
