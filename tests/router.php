<?php
/**
 * Router script for PHP's built-in web server (php -S ... tests/router.php).
 * It does what Apache does through .htaccess: send /api/v1/... to the front
 * controller and refuse direct access to api/lib, api/vendor and api/openapi.
 * Not needed on XAMPP.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (preg_match('#/api/(lib|vendor|openapi|logs)/#', $path) || preg_match('#(^|/)\.(env|git)#', $path)
    || preg_match('#/(tests|tools|evidence|xampp)(/|$)#', $path) || preg_match('#\.(env|example|sql|md|log|yml|yaml|patch|bak)$#', $path)) {
    http_response_code(403);
    exit;
}
if (preg_match('#/dist/[A-Za-z0-9._-]+\.html$#', $path)) {   // LAB 9: dist/*.html carry no CSP nonce; only page.php may serve them (.htaccess does the same)
    http_response_code(403);
    exit;
}
if (preg_match('#/api/v1(/|$)#', $path)) {
    require __DIR__ . '/../api/v1/index.php';
    return true;
}
// Lab 6: HTML pages go through page.php (nonce CSP, headers, server-side role checks), exactly as .htaccess does on Apache.
if ($path === '/' || preg_match('#^/[a-z0-9-]+\.html$#', $path)) {
    require __DIR__ . '/../page.php';
    return true;
}
// LAB 9: sandbox mirror of the .htaccess caching / compression rules for static files (Apache does this itself on XAMPP).
if (getenv('ZAMOVE_STATIC_MIRROR') === '1') {
    require_once __DIR__ . '/static_mirror.php';
    if (static_mirror(dirname(__DIR__), $path)) { return true; }
}
return false;
