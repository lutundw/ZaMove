<?php
declare(strict_types=1);

/**
 * ZaMove API v1 - single entry point (front controller).
 *
 * The router is generated FROM the OpenAPI contract: a request is only accepted if
 * api/openapi/openapi.json describes its path and method. The pipeline below runs
 * in this order, and any step can end the request with an RFC 7807 response:
 *
 *   1  HTTPS only (308 upgrade for GET, 403 otherwise)  + security headers / HSTS
 *   2  route lookup in the contract                     -> 404 / 405
 *   3  same-origin check on state-changing methods      -> 403
 *   4  rate limit (x-rate-limit in the contract)        -> 429
 *   5  authentication when the operation has `security` -> 401
 *   6  query-string validation                          -> 400
 *   7  body: Content-Type 415, size 413, JSON 400, schema 422
 *   8  handler (business logic)
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/schema_validator.php';
require_once __DIR__ . '/../lib/ratelimit.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/handlers.php';

ini_set('display_errors', '0');                       // never leak PHP errors or SQL into a response

set_exception_handler(function (Throwable $e): void {
    error_log('[zamove] ' . $e::class . ': ' . $e->getMessage());
    problem(500, 'Internal Server Error', 'Something went wrong on our side. Please try again later.');
});

const MAX_BODY_BYTES = 10240;

// ---- 1. transport ----------------------------------------------------------
api_security_headers();
require_https();

// ---- 2. route lookup (contract-first routing) --------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$full   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$pos    = strpos($full, '/api/v1');
$path   = rtrim($pos === false ? $full : substr($full, $pos + strlen('/api/v1')), '/');

if ($path === '/openapi.json' && ($method === 'GET' || $method === 'HEAD')) {
    rate_limit('openapi', 60, 60);
    $doc = json_decode((string) file_get_contents(__DIR__ . '/../openapi/openapi.json'), false, 64, JSON_THROW_ON_ERROR);
    $doc->servers = [(object) ['url' => 'https://' . trusted_host() . (getenv('ZAMOVE_HTTPS_PORT') && getenv('ZAMOVE_HTTPS_PORT') !== '443' ? ':' . getenv('ZAMOVE_HTTPS_PORT') : '') . api_base_path()]];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

[$op, $allowed] = openapi_find_operation($path, $method);
if ($allowed === []) {
    problem(404, 'Not Found', 'No such endpoint. See /api-docs for the API contract.');
}
if ($op === null) {
    problem(405, 'Method Not Allowed', "This endpoint accepts: " . implode(', ', $allowed) . '.', [], ['Allow: ' . implode(', ', $allowed)]);
}

// ---- 3. CSRF defence in depth ----------------------------------------------
if (!in_array($method, ['GET', 'HEAD'], true)) {
    require_same_origin();
}

// ---- 4. rate limit -----------------------------------------------------------
$rl = $op->{'x-rate-limit'} ?? null;
rate_limit($op->operationId, (int) ($rl->limit ?? 60), (int) ($rl->windowSeconds ?? 60));

// ---- 5. authentication -------------------------------------------------------
$ctx = ['user' => null, 'query' => [], 'body' => null];
if (!empty($op->security)) {
    $ctx['user'] = require_auth();
}

// ---- 6. query string ---------------------------------------------------------
$ctx['query'] = validate_query_or_400($op);

// ---- 7. request body -----------------------------------------------------------
$schemaName = openapi_body_schema_name($op);
if ($schemaName !== null) {
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') {
        problem(415, 'Unsupported Media Type', 'Send the body as application/json.');
    }
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if ($raw === false || $raw === '') {
        problem(400, 'Bad Request', 'The request body is empty.');
    }
    if (strlen($raw) > MAX_BODY_BYTES) {
        problem(413, 'Payload Too Large', 'The request body must be ' . MAX_BODY_BYTES . ' bytes or fewer.');
    }
    try {
        $decoded = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        problem(400, 'Bad Request', 'The request body is not valid JSON.');
    }
    if (!($decoded instanceof stdClass)) {
        problem(400, 'Bad Request', 'The request body must be a JSON object.');
    }
    $ctx['body'] = validate_body_or_422($schemaName, $decoded);       // 422 + errors[] on failure
}

// ---- 8. business logic --------------------------------------------------------
[$status, $payload] = ('h_' . $op->operationId)($ctx);
json_response($status, $payload);
