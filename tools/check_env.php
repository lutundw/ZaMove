<?php
/**
 * Environment validation (Lab 6, Task 4.2): halts with exit code 1 when a required secret
 * is missing or weak. Run it before starting the server or in a deployment script:
 *
 *     php tools/check_env.php
 *
 * The API runs the same check on every request (api/lib/env.php), so a misconfigured server
 * answers 500 instead of running with default credentials. Secret VALUES are never printed.
 */
require __DIR__ . '/../api/lib/env.php';
$bad = zm_env_problems();
if ($bad) {
    fwrite(STDERR, "Environment validation FAILED:\n");
    foreach ($bad as $b) { fwrite(STDERR, "  - $b\n"); }
    exit(1);
}
echo "Environment validation OK (", zm_env('ZAMOVE_ENV', 'development'), ", driver ", zm_env('ZAMOVE_DB_DRIVER', 'mysql'), ")\n";
