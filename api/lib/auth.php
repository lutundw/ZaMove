<?php
declare(strict_types=1);

require_once __DIR__ . '/problem.php';
require_once __DIR__ . '/session.php';

/**
 * Lab 5, Task 3: authentication middleware.
 * A protected route calls require_auth() first; no valid session -> 401 and the handler never runs.
 */
function require_auth(): array
{
    $user = zm_session_user();
    if ($user === null) {
        problem(401, 'Unauthorized', 'Authentication is required. Log in and try again.', [],
                ['WWW-Authenticate: Cookie realm="zamove"']);
    }
    return $user;
}

/** Role check used after require_auth() (RBAC): wrong role -> 403. */
function require_role(array $user, array $roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        problem(403, 'Forbidden', 'Your account is not allowed to use this endpoint.');
    }
}
