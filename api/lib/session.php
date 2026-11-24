<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Lab 5, Tasks 2 and 3: server-side sessions with a hardened cookie.
 *
 *  - The session id is 32 random bytes from the operating system's CSPRNG
 *    (random_bytes), sent to the browser as 64 hex characters.
 *  - The database stores only SHA-256(id). A stolen copy of the `sessions` table
 *    therefore contains nothing that can be replayed as a cookie.
 *  - Lifetime: 8 hours absolute, 30 minutes idle. Both are enforced on the SERVER,
 *    whatever the cookie says.
 *  - The cookie is __Host-zamove_sid; Secure; HttpOnly; SameSite=Strict; Path=/ ,
 *    with no Domain attribute. The __Host- prefix makes browsers refuse the cookie unless
 *    it is Secure, host-only and Path=/, so it cannot be injected from a sibling sub-domain.
 */

const ZM_COOKIE        = '__Host-zamove_sid';
const ZM_ABSOLUTE_TTL  = 8 * 3600;
const ZM_IDLE_TTL      = 30 * 60;

function zm_now(): string
{
    return gmdate('Y-m-d H:i:s');                                  // UTC everywhere, so MySQL and PHP never disagree
}

function zm_cookie_options(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => true,                                        // sent over HTTPS only
        'httponly' => true,                                        // invisible to document.cookie (XSS cannot steal it)
        'samesite' => 'Strict',                                    // not sent on cross-site requests (CSRF)
    ];
}

/** Create the session row and send the cookie. Returns the absolute expiry as a Unix time. */
function zm_session_start_for(int $userId): int
{
    $token   = bin2hex(random_bytes(32));
    $created = time();
    $expires = $created + ZM_ABSOLUTE_TTL;

    $stmt = db()->prepare(
        'INSERT INTO sessions (session_id_hash, user_id, created_at, last_seen_at, expires_at, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        hash('sha256', $token), $userId, gmdate('Y-m-d H:i:s', $created), gmdate('Y-m-d H:i:s', $created),
        gmdate('Y-m-d H:i:s', $expires), substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);

    setcookie(ZM_COOKIE, $token, zm_cookie_options($expires));
    return $expires;
}

/** The session cookie value if it looks like one of ours, otherwise null. */
function zm_cookie_token(): ?string
{
    $t = $_COOKIE[ZM_COOKIE] ?? '';
    return (is_string($t) && preg_match('/^[a-f0-9]{64}$/', $t)) ? $t : null;
}

/**
 * Resolve the cookie to a signed-in user, or null when the session is missing,
 * unknown, revoked (row deleted), expired or idle for too long.
 */
function zm_session_user(): ?array
{
    $token = zm_cookie_token();
    if ($token === null) {
        return null;
    }
    $hash = hash('sha256', $token);

    $stmt = db()->prepare(
        'SELECT s.last_seen_at, s.expires_at, u.user_id, u.full_name, u.email, u.phone, u.role, u.account_status
           FROM sessions s JOIN users u ON u.user_id = s.user_id
          WHERE s.session_id_hash = ?'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;                                               // never existed, or was revoked by logout
    }

    $now      = time();
    $expired  = strtotime($row['expires_at'] . ' UTC') <= $now;
    $idle     = strtotime($row['last_seen_at'] . ' UTC') <= $now - ZM_IDLE_TTL;
    if ($expired || $idle || $row['account_status'] === 'suspended') {
        db()->prepare('DELETE FROM sessions WHERE session_id_hash = ?')->execute([$hash]);
        return null;
    }

    if (strtotime($row['last_seen_at'] . ' UTC') < $now - 60) {    // sliding idle window, written at most once a minute
        db()->prepare('UPDATE sessions SET last_seen_at = ? WHERE session_id_hash = ?')->execute([zm_now(), $hash]);
    }
    unset($row['last_seen_at'], $row['expires_at']);
    return $row;
}

/** Logout: delete the server-side record, then expire the cookie in the browser. */
function zm_session_destroy_current(): void
{
    $token = zm_cookie_token();
    if ($token !== null) {
        db()->prepare('DELETE FROM sessions WHERE session_id_hash = ?')->execute([hash('sha256', $token)]);
    }
    setcookie(ZM_COOKIE, '', zm_cookie_options(1));               // same attributes, expiry in 1970 -> browser deletes it
}

/** Remove sessions that can no longer be used (called on login). */
function zm_session_purge_expired(): void
{
    db()->prepare('DELETE FROM sessions WHERE expires_at <= ? OR last_seen_at <= ?')
        ->execute([zm_now(), gmdate('Y-m-d H:i:s', time() - ZM_IDLE_TTL)]);
}
