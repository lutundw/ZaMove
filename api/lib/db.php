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

    if ($c['driver'] === 'sqlite') {                      // automated tests only
        $pdo = new PDO('sqlite:' . $c['sqlite_path'], null, null, $opts);
    } else {                                              // XAMPP MySQL / MariaDB
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
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
