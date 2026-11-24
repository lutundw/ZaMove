<?php
declare(strict_types=1);

/**
 * Lab 6, Task 3: one place that sets the defence-in-depth HTTP headers (the PHP counterpart of
 * Helmet.js). Used for JSON responses (api/lib/http.php, api/lib/middleware.php) and for HTML pages
 * (page.php), so the two can never drift apart.
 */

/** A fresh, unguessable value for each page response: 128 random bits, base64 encoded. */
function csp_new_nonce(): string
{
    return base64_encode(random_bytes(16));
}

/** LAB 8: wss:// to our own host (older Safari does not treat 'self' as covering WebSockets). */
function csp_ws_origin(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) === 1 ? "wss://$host" : '';
}

/** The Content-Security-Policy for an HTML page that uses $nonce on its <script> tags. */
function csp_for_page(string $nonce, string $reportUri = 'api/csp-report.php'): string
{
    return implode('; ', [
        "default-src 'self'",
        // Only <script> tags that carry THIS response's nonce run. No 'self', no 'strict-dynamic' (it would let
        // a script that is already running add inline scripts), no 'unsafe-inline', no 'unsafe-eval'.
        // LAB 9: 'self' is added so that route chunks loaded with import() (code splitting) may run. Inline scripts and
        // scripts from other origins are still blocked; only files served from OUR origin are trusted.
        "script-src 'nonce-$nonce' 'self'",
        "script-src-attr 'none'",                 // no onclick="..." style handlers
        "style-src 'self'",                       // no inline <style> or style="..." (all styling is in css/)
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self' " . csp_ws_origin(),   // fetch() and WebSocket (wss://same host) only  -- LAB 8: wss added
        "object-src 'none'",
        "base-uri 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
        "report-uri $reportUri",
    ]);
}

/**
 * @param string|null $csp  the CSP value to send (pages pass csp_for_page(); null = strict API policy)
 */
function send_security_headers(?string $csp = null, bool $cacheable = false): void
{
    header_remove('X-Powered-By');
    header('Content-Security-Policy: ' . ($csp ?? "default-src 'none'; frame-ancestors 'none'"));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Frame-Options: DENY');                                        // older browsers that ignore frame-ancestors
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if (!$cacheable) {
        header('Cache-Control: no-store');
    }
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'
          || (getenv('ZAMOVE_TRUST_PROXY') === '1' && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
