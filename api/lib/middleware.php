<?php
declare(strict_types=1);

require_once __DIR__ . '/problem.php';
require_once __DIR__ . '/validator.php';

/**
 * Request middleware chain for JSON endpoints.
 *
 * Each step either passes the request on or ends it with an RFC 7807
 * response, so the endpoint code only ever sees a validated payload:
 *
 *   security_headers -> require_method -> require_json_content_type
 *     -> read_json_body -> validate_body (in validator.php)
 *
 * Status codes used:
 *   405 wrong HTTP method            415 body is not application/json
 *   413 body larger than the limit   400 body is empty / malformed JSON / not an object
 *   422 well-formed JSON, but one or more fields are invalid
 */

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        problem(
            405,
            'Method Not Allowed',
            "This endpoint only accepts $method requests.",
            [],
            ["Allow: $method"]
        );
    }
}

function require_json_content_type(): void
{
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') {
        problem(415, 'Unsupported Media Type', 'Send the body as application/json.');
    }
}

/** Read, size-limit and decode the JSON body into an associative array. */
function read_json_body(int $maxBytes = 10240): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || $raw === '') {
        problem(400, 'Bad Request', 'The request body is empty.');
    }
    if (strlen($raw) > $maxBytes) {
        problem(413, 'Payload Too Large', "The request body must be $maxBytes bytes or fewer.");
    }

    try {
        // assoc=false so that {} (object) and [] (list) can be told apart
        $decoded = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        problem(400, 'Bad Request', 'The request body is not valid JSON.');
    }

    if (!($decoded instanceof stdClass)) {
        problem(400, 'Bad Request', 'The request body must be a JSON object.');
    }
    return (array) $decoded;
}

/** Validate against a schema; on failure answer 422 with every field error. */
function validate_or_422(array $schema, array $body): array
{
    [$clean, $errors] = validate_body($schema, $body);

    if ($errors !== []) {
        $n = count($errors);
        problem(
            422,
            'Validation failed',
            "$n field" . ($n === 1 ? '' : 's') . ' failed validation. Fix the listed fields and try again.',
            ['errors' => $errors]
        );
    }
    return $clean;
}
