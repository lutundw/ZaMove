<?php
declare(strict_types=1);

/**
 * Database settings (Lab 6: no credentials in source code).
 *
 * Every value comes from the environment or from the .env file (see api/lib/env.php and
 * .env.example). There is deliberately NO default password and no default account: when a
 * setting is missing, api/lib/env.php refuses to start the application.
 *
 * ZAMOVE_DB_DRIVER=sqlite is used ONLY by the automated tests in /tests.
 */
require_once __DIR__ . '/lib/env.php';

return [
    'db' => [
        'driver'      => zm_env('ZAMOVE_DB_DRIVER', 'mysql'),
        'host'        => zm_env('ZAMOVE_DB_HOST', ''),
        'name'        => zm_env('ZAMOVE_DB_NAME', ''),
        'user'        => zm_env('ZAMOVE_DB_USER', ''),
        'pass'        => zm_env('ZAMOVE_DB_PASS', ''),
        'sqlite_path' => zm_env('ZAMOVE_SQLITE', ''),
    ],
    'app_key' => zm_env('ZAMOVE_APP_KEY', ''),
];
