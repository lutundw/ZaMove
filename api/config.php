<?php
declare(strict_types=1);

/**
 * Database settings.
 *
 * XAMPP defaults are used when no environment variable is set:
 * host localhost, user "root", empty password, database "zamumove"
 * (the database created by schema.sql).
 *
 * For anything beyond local development, use the limited account from
 * schema.sql ("zamumove_app") instead of root.
 *
 * ZAMOVE_DB_DRIVER=sqlite is used ONLY by the automated tests in /tests.
 */
return [
    'db' => [
        'driver'      => getenv('ZAMOVE_DB_DRIVER') ?: 'mysql',
        'host'        => getenv('ZAMOVE_DB_HOST')   ?: 'localhost',
        'name'        => getenv('ZAMOVE_DB_NAME')   ?: 'zamumove',
        'user'        => getenv('ZAMOVE_DB_USER')   ?: 'root',
        'pass'        => getenv('ZAMOVE_DB_PASS')   ?: '',
        'sqlite_path' => getenv('ZAMOVE_SQLITE')    ?: '',
    ],
];
