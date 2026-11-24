<?php
declare(strict_types=1);

/**
 * Lab 6, Task 3: serves every HTML page of the site with
 *   - a fresh Content-Security-Policy nonce on every <script> tag (strict CSP, no 'unsafe-inline'),
 *   - the secondary defence headers (nosniff, Referrer-Policy, COOP ...),
 *   - SERVER-SIDE role checks for pages that declare data-roles (A01): admin.html is never sent to
 *     someone who is not an admin, whatever JavaScript does in the browser.
 *
 * .htaccess (Apache) and tests/router.php (PHP built-in server) send  /name.html  here.
 */

require_once __DIR__ . '/api/lib/env.php';
require_once __DIR__ . '/api/lib/security_headers.php';
require_once __DIR__ . '/api/lib/cache.php';              // LAB 9

zm_env_require();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$name = 'index';
$status = 200;
if (preg_match('#/([a-z0-9-]+)\.html$#', $path, $m) === 1) {
    $name = $m[1];
} elseif (!preg_match('#/$#', $path)) {
    $name = '404';
    $status = 404;
}
$file = __DIR__ . '/' . $name . '.html';                 // $name is limited to [a-z0-9-]: no ../ is possible
if (!is_file($file)) {
    $file = __DIR__ . '/404.html';
    $status = 404;
}
// LAB 9: after `node tools/build.js` the pages in dist/ (minified, content-hashed asset names) are served instead of the sources.
$built = __DIR__ . '/dist/' . basename($file);
if (is_file(__DIR__ . '/dist/manifest.json') && is_file($built) && (zm_env('ZAMOVE_DIST', '1') ?? '1') !== '0') {
    $file = $built;
}
$html = (string) file_get_contents($file);

// ---- server-side authorisation: the page's own data-roles attribute is the single source of truth ----
if ($status === 200 && preg_match('/data-roles="([a-z,]+)"/', $html, $rm) === 1) {
    require_once __DIR__ . '/api/lib/session.php';
    require_once __DIR__ . '/api/lib/authz.php';
    $roles = explode(',', $rm[1]);
    $user  = zm_session_user();
    $home  = ['customer' => 'bookings.html', 'operator' => 'operator.html', 'admin' => 'admin.html'];
    if ($user === null || !in_array($user['role'], $roles, true)) {
        if ($user !== null) {
            security_log('page_denied', ['page' => $name, 'user_id' => (int) $user['user_id'], 'role' => $user['role']]);
        }
        send_security_headers(csp_for_page(csp_new_nonce()));
        // REAL APP: a visitor who is not signed in is sent to login and brought back to the same page (and ?trip=..&pax=..) afterwards
        $back = $name . '.html' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
        header('Location: ' . ($user === null ? 'login.html?next=' . rawurlencode($back) : ($home[$user['role']] ?? 'index.html')), true, 302);
        exit;
    }
}

$nonce = csp_new_nonce();
$html  = preg_replace_callback('/<script\b([^>]*)>/i', static function (array $m) use ($nonce): string {
    return stripos($m[1], 'nonce=') !== false ? $m[0] : '<script' . $m[1] . ' nonce="' . $nonce . '">';
}, $html);

http_response_code($status);
send_security_headers(csp_for_page($nonce), true);
header('Cache-Control: private, no-cache');               // the nonce changes on every response, so HTML is always revalidated
header('Vary: Cookie');
header('Content-Type: text/html; charset=utf-8');
$html = zm_compress($html);                               // LAB 9: Brotli / gzip
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    echo $html;
}
