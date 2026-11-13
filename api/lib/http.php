<?php
declare(strict_types=1);

require_once __DIR__ . '/problem.php';

/**
 * Transport-level helpers (Lab 5): HTTPS enforcement, HSTS, same-origin check.
 *
 * Apache (httpd-ssl.conf + the port-80 redirect) is the first line of defence.
 * These functions are the second: if a request ever reaches PHP over plain
 * HTTP, the API refuses to serve it.
 */

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // Behind a TLS-terminating proxy. Only trusted when the operator switches it on,
    // because any client can send an X-Forwarded-Proto header of its own.
    return getenv('ZAMOVE_TRUST_PROXY') === '1'
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** X-Forwarded-For is client-controlled, so the rate limiter uses the socket address. */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Host header, only if it is on the allow-list (prevents Host-header poisoning of redirects and the spec's server URL). */
function trusted_host(): string
{
    $allowed = array_map('trim', explode(',', getenv('ZAMOVE_ALLOWED_HOSTS') ?: 'localhost,127.0.0.1'));
    $host    = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost') ?? 'localhost');
    if (!in_array($host, $allowed, true)) {
        problem(400, 'Bad Request', 'Unrecognised Host header.');
    }
    return $host;
}

/** Headers added to every API response. */
function api_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    if (is_https()) {
        // HSTS: for one year the browser will use HTTPS only for this host, even if the user types http://
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** Upgrade or refuse plain-HTTP requests. */
function require_https(): void
{
    if (is_https() || getenv('ZAMOVE_ALLOW_HTTP') === '1') {      // the override is for local debugging only
        return;
    }
    $host   = trusted_host();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET' || $method === 'HEAD') {
        $port   = (int) (getenv('ZAMOVE_HTTPS_PORT') ?: 443);
        $target = 'https://' . $host . ($port === 443 ? '' : ':' . $port) . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $target, true, 308);                 // 308 keeps the method
        exit;
    }
    // A POST that arrived over HTTP has already exposed its body, so redirecting it would be pointless.
    problem(403, 'HTTPS Required', 'This API is only available over HTTPS. Use the https:// address.');
}

/**
 * CSRF defence in depth for state-changing requests: browsers always send an Origin
 * header on cross-site POST/DELETE, so a foreign Origin is refused. (The primary
 * defence is the SameSite=Strict cookie.) Non-browser clients send no Origin.
 */
function require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }
    $oHost = parse_url($origin, PHP_URL_HOST);
    $oPort = parse_url($origin, PHP_URL_PORT);
    $hHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '') ?? '');
    preg_match('/:(\d+)$/', $_SERVER['HTTP_HOST'] ?? '', $m);
    $hPort = isset($m[1]) ? (int) $m[1] : null;

    if (strtolower((string) $oHost) !== $hHost || ($oPort ?? null) !== $hPort) {
        problem(403, 'Forbidden', 'Cross-origin requests are not allowed.');
    }
}

/** Path of this API as seen by the browser, e.g. /zamove/api/v1 (works in a sub-folder of htdocs). */
function api_base_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $pos  = strpos($path, '/api/v1');
    return $pos === false ? '/api/v1' : substr($path, 0, $pos) . '/api/v1';
}
