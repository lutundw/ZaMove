<?php
declare(strict_types=1);

/**
 * Lab 6, Task 4: secrets live in the environment, not in the source code.
 *
 *   zm_env('ZAMOVE_DB_PASS')        read one setting (real environment first, then the .env file)
 *   zm_env_problems()               list of problems with the current configuration (empty = OK)
 *   zm_env_require()                halt (HTTP 500 / exit 1) when the configuration is not valid
 *
 * The .env file is read ONCE per request/process. Real environment variables win over the file,
 * so a production server can set them in Apache (SetEnv) or the service manager and ship no file.
 * The file is looked for in ZAMOVE_ENV_FILE, then in the folder above the project (outside the
 * web root, the safest place), then in the project folder (blocked from the web by .htaccess).
 */

/** Settings the application cannot start without. type: enum | string | secret | port | list */
const ZM_ENV_SCHEMA = [
    'ZAMOVE_ENV'           => ['type' => 'enum',   'values' => ['development', 'production', 'test'], 'default' => 'development'],
    'ZAMOVE_DB_DRIVER'     => ['type' => 'enum',   'values' => ['mysql', 'sqlite'], 'default' => 'mysql'],
    'ZAMOVE_APP_KEY'       => ['type' => 'secret', 'min' => 32, 'required' => true],
    'ZAMOVE_ALLOWED_HOSTS' => ['type' => 'list',   'default' => 'localhost,127.0.0.1'],
];
/** Extra keys that are required only for one database driver. */
const ZM_ENV_MYSQL = [
    'ZAMOVE_DB_HOST' => ['type' => 'string', 'required' => true],
    'ZAMOVE_DB_NAME' => ['type' => 'string', 'required' => true, 'pattern' => '/^[A-Za-z0-9_]{1,64}$/'],
    'ZAMOVE_DB_USER' => ['type' => 'string', 'required' => true],
    'ZAMOVE_DB_PASS' => ['type' => 'secret', 'min' => 0, 'required' => true, 'allow_empty' => true],
];

function zm_env_file_values(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $root = dirname(__DIR__, 2);
    $explicit   = getenv('ZAMOVE_ENV_FILE');            // when set, ONLY this file is used (even if it does not exist)
    $candidates = $explicit !== false && $explicit !== '' ? [$explicit] : [dirname($root) . '/zamove.env', $root . '/.env'];
    $cache = [];
    foreach ($candidates as $file) {
        if (is_file($file) && is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if (preg_match('/^[A-Z][A-Z0-9_]*$/', $k) !== 1) {
                    continue;
                }
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                $cache[$k] = $v;
            }
            break;                                              // first file found wins
        }
    }
    return $cache;
}

/** Real environment first, then the .env file, then null. */
function zm_env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v !== false) {
        return $v;
    }
    $file = zm_env_file_values();
    return $file[$key] ?? $default;
}

/** Everything wrong with the configuration, as human-readable lines. Never includes secret values. */
function zm_env_problems(): array
{
    $schema = ZM_ENV_SCHEMA;
    if ((zm_env('ZAMOVE_DB_DRIVER') ?? 'mysql') === 'mysql') {
        $schema += ZM_ENV_MYSQL;
    }
    $bad = [];
    foreach ($schema as $key => $rule) {
        $v = zm_env($key);
        if ($v === null || ($v === '' && empty($rule['allow_empty']))) {
            if (!empty($rule['required'])) {
                $bad[] = "$key is missing";
            }
            continue;
        }
        $v = $v === '' ? '' : $v;
        switch ($rule['type']) {
            case 'enum':
                if (!in_array($v, $rule['values'], true)) {
                    $bad[] = "$key must be one of: " . implode(', ', $rule['values']);
                }
                break;
            case 'secret':
                if (strlen($v) < ($rule['min'] ?? 0)) {
                    $bad[] = "$key is too short (needs at least {$rule['min']} characters)";
                }
                if (preg_match('/^(changeme|change_me|password|secret|example|xxxx)/i', $v) === 1) {
                    $bad[] = "$key still has a placeholder value";
                }
                break;
            default:
                if (isset($rule['pattern']) && preg_match($rule['pattern'], $v) !== 1) {
                    $bad[] = "$key has an invalid format";
                }
        }
    }
    if ((zm_env('ZAMOVE_ENV') ?? 'development') === 'production' && (zm_env('ZAMOVE_DB_DRIVER') ?? 'mysql') === 'mysql') {
        if (zm_env('ZAMOVE_DB_USER') === 'root') {
            $bad[] = 'ZAMOVE_DB_USER must not be root in production (use the limited zamumove_app account)';
        }
        if (strlen((string) zm_env('ZAMOVE_DB_PASS')) < 12) {
            $bad[] = 'ZAMOVE_DB_PASS must be at least 12 characters in production';
        }
    }
    return $bad;
}

/** Fail closed: log the reasons on the server, tell the client nothing useful. */
function zm_env_require(): void
{
    $bad = zm_env_problems();
    if ($bad === []) {
        return;
    }
    error_log('[zamove] configuration invalid: ' . implode('; ', $bad));
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Configuration invalid:\n  - " . implode("\n  - ", $bad) . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/problem+json; charset=utf-8');
    echo json_encode(['type' => 'https://zamove.example/problems/internal-server-error', 'title' => 'Internal Server Error',
                      'status' => 500, 'detail' => 'The server is not configured correctly.']);
    exit;
}
