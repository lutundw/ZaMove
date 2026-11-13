<?php
declare(strict_types=1);

require_once __DIR__ . '/problem.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

/**
 * Lab 4, Task 4: fixed-window rate limiting, stored in the database so the
 * counter is shared by every Apache worker (a PHP variable would reset on each request).
 *
 * Limits come from the contract (`x-rate-limit` on each operation, default 60 / minute).
 * Every response carries  RateLimit-Limit / RateLimit-Remaining / RateLimit-Reset;
 * a breach returns 429 with Retry-After.  Key = route + SHA-256(client IP).
 */

function rate_limit(string $bucket, int $limit = 60, int $window = 60): void
{
    $now         = time();
    $windowStart = $now - ($now % $window);
    $key         = $bucket . ':' . substr(hash('sha256', client_ip()), 0, 32);
    $pdo         = db();

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $sql = 'INSERT INTO rate_limits (rl_key, window_start, hits) VALUES (?, ?, 1)
                ON CONFLICT(rl_key) DO UPDATE SET
                  hits = CASE WHEN window_start = excluded.window_start THEN hits + 1 ELSE 1 END,
                  window_start = excluded.window_start';
    } else {                                                   // MySQL / MariaDB
        $sql = 'INSERT INTO rate_limits (rl_key, window_start, hits) VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE
                  hits = IF(window_start = VALUES(window_start), hits + 1, 1),
                  window_start = VALUES(window_start)';
    }
    $pdo->prepare($sql)->execute([$key, $windowStart]);        // one atomic statement: no race between workers

    $sel = $pdo->prepare('SELECT hits FROM rate_limits WHERE rl_key = ?');
    $sel->execute([$key]);
    $hits = (int) $sel->fetchColumn();

    $remaining = max(0, $limit - $hits);
    $reset     = max(0, $windowStart + $window - $now);
    header("RateLimit-Limit: $limit");
    header("RateLimit-Remaining: $remaining");
    header("RateLimit-Reset: $reset");

    if (random_int(1, 100) === 1) {                            // housekeeping: forget old windows
        $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$now - 3600]);
    }

    if ($hits > $limit) {
        problem(429, 'Too Many Requests',
            "Rate limit of $limit requests per $window seconds exceeded. Try again in $reset seconds.",
            ['retry_after' => $reset], ["Retry-After: $reset"]);
    }
}
