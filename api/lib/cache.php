<?php
declare(strict_types=1);

/**
 * LAB 9, Task 4: HTTP caching (RFC 9111) and response compression for the API and the HTML pages.
 *
 *   zm_cache_policy('public')   public, read-only data  -> Cache-Control: public, max-age=60, stale-while-revalidate=300
 *                                                          + a strong-validator ETag; a matching If-None-Match gets 304 and no body
 *   zm_cache_policy('private')  anything that depends on the signed-in user (default) -> Cache-Control: private, no-store
 *   zm_deliver($body, $type)    applies the policy, then compresses (Brotli if the PHP extension exists, otherwise gzip)
 *
 * Static files (css, js, images) never reach PHP; .htaccess gives them their headers (immutable for hashed names).
 */

function zm_cache_policy(?string $set = null): string
{
    static $policy = 'private';                       // safe default: never let a shared cache keep user data
    if ($set !== null) {
        $policy = $set;
    }
    return $policy;
}

/** Best encoding the client accepts and we can produce: 'br', 'gzip' or ''. */
function zm_pick_encoding(): string
{
    $ae = strtolower($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
    if (function_exists('brotli_compress') && preg_match('/\bbr\b/', $ae)) {
        return 'br';
    }
    return (function_exists('gzencode') && preg_match('/\bgzip\b/', $ae)) ? 'gzip' : '';
}

/** Compress $body when it is worth it, set Content-Encoding / Vary / Content-Length, return the bytes to send. */
function zm_compress(string $body): string
{
    header('Vary: Accept-Encoding', false);
    $enc = zm_pick_encoding();
    if ($enc === '' || strlen($body) < 512 || zm_env_flag('ZAMOVE_PHP_COMPRESS') === false) {
        header('Content-Length: ' . strlen($body));
        return $body;
    }
    $out = $enc === 'br' ? (string) brotli_compress($body, 5) : (string) gzencode($body, 6);
    header('Content-Encoding: ' . $enc);
    header('Content-Length: ' . strlen($out));
    return $out;
}

function zm_env_flag(string $key): bool
{
    return (zm_env($key, '1') ?? '1') !== '0';
}

/** Does the request's If-None-Match list contain $etag (weak comparison, RFC 9110 section 13.1.2)? */
function zm_etag_matches(string $etag): bool
{
    $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($inm === '') {
        return false;
    }
    if (trim($inm) === '*') {
        return true;
    }
    $strip = static fn(string $t): string => preg_replace('#^W/#', '', trim($t)) ?? '';
    foreach (explode(',', $inm) as $candidate) {
        if ($strip($candidate) === $strip($etag)) {
            return true;
        }
    }
    return false;
}

/** Send a JSON/HTML body with the cache headers of the current policy. Returns normally (the caller echoes). */
function zm_deliver(int $status, string $body): string
{
    if (zm_cache_policy() === 'public' && $status === 200) {
        $etag = '"' . substr(hash('sha256', $body), 0, 32) . '"';
        header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
        header('ETag: ' . $etag);
        header('Vary: Accept-Encoding', false);
        if (zm_etag_matches($etag)) {
            header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 304 Not Modified', true, 304);   // same style as send_status() in problem.php
            header_remove('Content-Type');
            header_remove('Content-Length');
            exit;                                     // 304: headers only, nothing to transfer
        }
    } else {
        header('Cache-Control: private, no-store');
        header('Vary: Cookie', false);
    }
    return zm_compress($body);
}
