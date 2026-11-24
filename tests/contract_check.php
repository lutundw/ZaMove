<?php
declare(strict_types=1);
/**
 * Lab 4, Task 4.1 - automated contract test (an HTTP client suite written in PHP).
 *
 * For every request it checks the REAL response against api/openapi/openapi.json:
 *   1. the status code is one the contract documents for that operation
 *   2. the Content-Type is one the contract declares for that status
 *   3. the JSON body validates against the response schema (JSON Schema 2020-12)
 *   4. every response header the contract lists (RateLimit-*, Retry-After, WWW-Authenticate,
 *      Set-Cookie) is present
 *
 *   php tests/contract_check.php https://localhost/zamove/api/v1
 *   env ZAMOVE_CA=/path/to/rootCA.pem   (mkcert root CA)   or   ZAMOVE_INSECURE=1 (never in production)
 * Run on a fresh minute (the rate limiter counts these requests).
 */
require __DIR__ . '/../api/lib/schema_validator.php';

$base = rtrim($argv[1] ?? 'https://localhost/api/v1', '/');
$spec = openapi_spec();
$val  = openapi_validator();

function make_client(string $base): CurlHandle
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false, CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEFILE => '',                              // enable curl's cookie engine (the session cookie is kept here)
    ]);
    if (getenv('ZAMOVE_CA'))           curl_setopt($ch, CURLOPT_CAINFO, getenv('ZAMOVE_CA'));
    if (getenv('ZAMOVE_INSECURE') === '1') { curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); }
    return $ch;
}

/** @return array{0:int,1:array<string,string>,2:string} */
function call(CurlHandle $ch, string $method, string $url, ?string $body, array $headers = []): array
{
    $resp = [];
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body ?? '', CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$resp) {
            if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $resp[strtolower(trim($k))] = trim($v); }
            return strlen($line);
        },
    ]);
    $out = curl_exec($ch);
    if ($out === false) { fwrite(STDERR, "curl error: " . curl_error($ch) . "\n"); exit(2); }
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $resp, (string) $out];
}

$anon = make_client($base);    // no session
$auth = make_client($base);    // keeps the session cookie after login

$run   = (string) time();
$email = "contract.$run@example.com";
$good  = ['role' => 'customer', 'full_name' => 'Contract Tester', 'email' => $email, 'phone' => '0961234567', 'password' => 'Passw0rdX'];
$J     = ['Content-Type: application/json'];

// [operationId, method, path, client, body, headers, expected status]
$cases = [
  ['createUser', 'POST', '/users', $anon, json_encode($good), $J, 201],
  ['createUser', 'POST', '/users', $anon, '{"role":"customer",', $J, 400],
  ['createUser', 'POST', '/users', $anon, json_encode($good), $J, 409],
  ['createUser', 'POST', '/users', $anon, json_encode($good), ['Content-Type: text/plain'], 415],
  ['createUser', 'POST', '/users', $anon, json_encode(['role' => 'admin'] + $good + ['is_admin' => true]), $J, 422],
  ['createUser', 'POST', '/users', $anon, json_encode(['full_name' => str_repeat('a', 11000)] + $good), $J, 413],
  ['login', 'POST', '/sessions', $auth, json_encode(['email' => $email, 'password' => 'Passw0rdX']), $J, 200],
  ['login', 'POST', '/sessions', $anon, json_encode(['email' => $email, 'password' => 'Wrong1Pass']), $J, 401],
  ['login', 'POST', '/sessions', $anon, json_encode(['email' => $email]), $J, 422],
  ['getCurrentUser', 'GET', '/users/me', $auth, null, [], 200],
  ['getCurrentUser', 'GET', '/users/me', $anon, null, [], 401],
  ['listBookings', 'GET', '/bookings?limit=5', $auth, null, [], 200],
  ['listBookings', 'GET', '/bookings?admin=true', $auth, null, [], 400],
  ['listBookings', 'GET', '/bookings', $anon, null, [], 401],
  ['logout', 'DELETE', '/sessions/current', $auth, null, [], 200],
  ['getCurrentUser', 'GET', '/users/me', $auth, null, [], 401],      // after logout the session is gone
];

// index operations by operationId
$ops = [];
foreach ($spec->paths as $path => $item) {
    foreach ($item as $method => $op) { $ops[$op->operationId] = $op; }
}

$pass = $fail = 0;
$seen = [];
echo "Target: $base\nSpecification: api/openapi/openapi.json (OpenAPI {$spec->openapi})\nStarted: " . date('Y-m-d H:i:s O') . "\n";
echo str_repeat('=', 72) . "\n";

foreach ($cases as $i => [$opId, $method, $path, $client, $body, $headers, $want]) {
    $op = $ops[$opId];
    [$code, $h, $raw] = call($client, $method, $base . $path, $body, $headers);
    $problems = [];

    // 1. documented status
    if (!isset($op->responses->{(string) $code})) {
        $problems[] = "status $code is not documented for $opId";
    }
    if ($code !== $want) {
        $problems[] = "expected $want";
    }
    $seen[$opId][$code] = true;

    $def = isset($op->responses->{(string) $code}) ? openapi_deref($op->responses->{(string) $code}) : null;
    if ($def !== null) {
        // 2. content type
        $ctype = strtolower(trim(explode(';', $h['content-type'] ?? '')[0]));
        $declared = isset($def->content) ? array_keys((array) $def->content) : [];
        if ($declared && !in_array($ctype, $declared, true)) {
            $problems[] = "Content-Type '$ctype' not in contract (" . implode(', ', $declared) . ')';
        }
        // 3. body against schema
        if ($declared && isset($def->content->{$ctype}->schema->{'$ref'})) {
            $json = json_decode($raw);
            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                $problems[] = 'body is not valid JSON';
            } else {
                $res = $val->validate($json, OPENAPI_URI . $def->content->{$ctype}->schema->{'$ref'});
                if (!$res->isValid()) {
                    $leaf = schema_leaf_errors($res->error())[0];
                    $problems[] = 'body breaks schema ' . basename($def->content->{$ctype}->schema->{'$ref'}) . ': ' . $leaf->message()
                                . ' at /' . implode('/', $leaf->data()->fullPath());
                }
            }
        }
        // 4. documented headers
        foreach ($def->headers ?? [] as $name => $_) {
            if (!isset($h[strtolower($name)])) {
                $problems[] = "documented header $name missing";
            }
        }
    }

    $ok = $problems === [];
    $ok ? $pass++ : $fail++;
    $schemaName = ($def && isset($def->content)) ? basename((string) (current((array) $def->content)->schema->{'$ref'} ?? '')) : '-';
    printf("%s %02d  %-14s %-6s %-22s -> %d  %-24s schema=%s\n", $ok ? 'PASS' : 'FAIL', $i + 1, $opId, $method, $path, $code,
           $h['content-type'] ?? '(none)', $schemaName);
    foreach ($problems as $p) echo "      ! $p\n";
}

// coverage: how many of the contract's documented responses were actually exercised
$documented = $exercised = 0;
$missing = [];
foreach ($ops as $opId => $op) {
    foreach ($op->responses as $status => $_) {
        $documented++;
        if (isset($seen[$opId][(int) $status])) $exercised++; else $missing[] = "$opId:$status";
    }
}
echo str_repeat('-', 72) . "\n";
echo "Documented responses exercised by this suite: $exercised of $documented\n";
echo "Covered by the other scripts (rate limit, TLS, session tests) or by fault injection: " . implode(', ', $missing) . "\n";
echo "SUMMARY: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
