<?php
/**
 * Router script for PHP's built-in web server (php -S ... tests/router.php).
 * It does what Apache does through .htaccess: send /api/v1/... to the front
 * controller and refuse direct access to api/lib, api/vendor and api/openapi.
 * Not needed on XAMPP.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (preg_match('#/api/(lib|vendor|openapi)/#', $path)) {
    http_response_code(403);
    exit;
}
if (preg_match('#/api/v1(/|$)#', $path)) {
    require __DIR__ . '/../api/v1/index.php';
    return true;
}
return false;
