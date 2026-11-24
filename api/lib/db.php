<?php
declare(strict_types=1);

/** One shared PDO connection. Prepared statements are real (not emulated). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c    = (require __DIR__ . '/../config.php')['db'];
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // ---- LAB 7: connection-pool settings (read from the environment; see xampp/zamove-pool.conf) ----
    //  ZAMOVE_DB_PERSISTENT=1   reuse the connection between requests handled by the same PHP worker
    //  ZAMOVE_DB_TIMEOUT=5      seconds to wait to connect / for a lock (the "acquire timeout")
    //  ZAMOVE_DB_IDLE=60        seconds MySQL may keep an idle connection before closing it (wait_timeout)
    $opts[PDO::ATTR_PERSISTENT] = getenv('ZAMOVE_DB_PERSISTENT') === '1';
    $opts[PDO::ATTR_TIMEOUT]    = (int) (getenv('ZAMOVE_DB_TIMEOUT') ?: 5);
    // ---- end LAB 7 ----

    if ($c['driver'] === 'sqlite') {                      // automated tests only
        $pdo = new PDO('sqlite:' . $c['sqlite_path'], null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');           // LAB 7: SQLite enforces foreign keys only when asked
    } else {                                              // XAMPP MySQL / MariaDB
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
        $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET SESSION wait_timeout=' . (int) (getenv('ZAMOVE_DB_IDLE') ?: 60)   // LAB 7
                                            . ', innodb_lock_wait_timeout=' . (int) (getenv('ZAMOVE_DB_TIMEOUT') ?: 5);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
    }
    return $pdo;
}

/** True when an exception is a UNIQUE-constraint violation (MySQL 1062 / SQLite UNIQUE). */
function is_duplicate_key(PDOException $e): bool
{
    if ((string) $e->getCode() !== '23000') {
        return false;
    }
    $driverCode = $e->errorInfo[1] ?? null;
    return $driverCode === 1062 || stripos($e->getMessage(), 'UNIQUE') !== false;
}
