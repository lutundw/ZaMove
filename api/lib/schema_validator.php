<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/problem.php';
require_once __DIR__ . '/sanitize.php';

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Lab 4, Task 2: contract-driven request validation.
 *
 * The OpenAPI 3.1 document (api/openapi/openapi.json) is the single source of truth.
 * Request bodies and query parameters are checked against the JSON Schemas in
 * components/ by the Opis JSON Schema library (draft 2020-12) BEFORE any handler runs.
 *
 *   malformed JSON / bad query string   -> 400 Bad Request   (problem+json)
 *   well-formed but breaks the schema   -> 422 Unprocessable (problem+json + errors[])
 *
 * Two vendor extensions (allowed by OpenAPI as `x-` keys) are read from the schema:
 *   x-sanitize  how to clean a string BEFORE validating it (text | email | phone | none)
 *   x-messages  human-friendly message per failing keyword (same wording as js/validation.js)
 */

const OPENAPI_FILE = __DIR__ . '/../openapi/openapi.json';
const OPENAPI_URI  = 'https://zamove.example/openapi.json';

/** The decoded OpenAPI document (objects, not arrays, so JSON {} and [] stay distinct). */
function openapi_spec(): stdClass
{
    static $spec = null;
    if ($spec === null) {
        $spec = json_decode((string) file_get_contents(OPENAPI_FILE), false, 64, JSON_THROW_ON_ERROR);
    }
    return $spec;
}

function openapi_validator(): Validator
{
    static $v = null;
    if ($v === null) {
        $v = new Validator(null, 25, false);                     // collect up to 25 errors, not just the first
        $v->resolver()->registerRaw(openapi_spec(), OPENAPI_URI); // so "#/components/schemas/X" resolves
    }
    return $v;
}

/** Follow a local {"$ref": "#/components/..."} one level. */
function openapi_deref(object $node): object
{
    while (isset($node->{'$ref'}) && is_string($node->{'$ref'}) && str_starts_with($node->{'$ref'}, '#/')) {
        $cur = openapi_spec();
        foreach (explode('/', substr($node->{'$ref'}, 2)) as $part) {
            $cur = $cur->{str_replace(['~1', '~0'], ['/', '~'], $part)};
        }
        $node = $cur;
    }
    return $node;
}

/** Look up the operation for a path and method; returns [op|null, allowedMethods[]]. */
function openapi_find_operation(string $path, string $method): array
{
    $paths  = openapi_spec()->paths;
    $params = [];
    $item   = $paths->{$path} ?? null;
    if ($item === null) {
        // Lab 6: templated paths such as /bookings/{id}. Each {name} matches one path segment.
        foreach ($paths as $template => $candidate) {
            if (!str_contains($template, '{')) {
                continue;
            }
            $regex = '#^' . preg_replace('/\\\{([A-Za-z_]+)\\\}/', '(?P<$1>[^/]+)', preg_quote($template, '#')) . '$#';
            if (preg_match($regex, $path, $m) === 1) {
                $item = $candidate;
                foreach ($m as $k => $v) {
                    if (is_string($k)) {
                        $params[$k] = $v;
                    }
                }
                break;
            }
        }
    }
    if ($item === null) {
        return [null, [], []];
    }
    $allowed = [];
    foreach (['get', 'post', 'put', 'patch', 'delete'] as $m) {
        if (isset($item->{$m})) {
            $allowed[] = strtoupper($m);
        }
    }
    return [$item->{strtolower($method)} ?? null, $allowed, $params];
}

/**
 * Check path parameters against the contract (type, minimum, maximum). A bad value is a 400 and
 * never reaches the database. Returns the values converted to their declared type.
 */
function validate_path_params_or_400(object $op, array $raw): array
{
    $clean = [];
    foreach ($op->parameters ?? [] as $p) {
        $def = openapi_deref($p);
        if (($def->in ?? '') !== 'path') {
            continue;
        }
        $v = $raw[$def->name] ?? '';
        if (($def->schema->type ?? 'string') === 'integer') {
            if (preg_match('/^[1-9]\d{0,9}$/', $v) !== 1 || (int) $v > ($def->schema->maximum ?? PHP_INT_MAX)) {
                problem(400, 'Bad Request', "Path parameter '{$def->name}' must be a positive whole number.", ['errors' => [[
                    'field' => $def->name, 'code' => 'type', 'message' => 'Must be a positive whole number.']]]);
            }
            $v = (int) $v;
        }
        $clean[$def->name] = $v;
    }
    return $clean;
}

function openapi_body_schema_name(object $op): ?string
{
    $ref = $op->requestBody->content->{'application/json'}->schema->{'$ref'} ?? null;
    return is_string($ref) ? basename($ref) : null;
}

/** Apply each property's x-sanitize rule to the matching string value. */
function sanitize_by_schema(string $schemaName, stdClass $data): stdClass
{
    $props = openapi_spec()->components->schemas->{$schemaName}->properties ?? new stdClass();
    foreach ($props as $name => $def) {
        if (!isset($data->{$name}) || !is_string($data->{$name})) {
            continue;                                              // wrong types are reported by the schema, not hidden
        }
        $data->{$name} = match ($def->{'x-sanitize'} ?? 'none') {
            'text'  => sanitize_text($data->{$name}),
            'email' => sanitize_email($data->{$name}),
            'phone' => sanitize_phone($data->{$name}),
            default => $data->{$name},
        };
    }
    return $data;
}

/** Collect the innermost errors of a Opis error tree. */
function schema_leaf_errors(ValidationError $e): array
{
    $subs = $e->subErrors();
    if ($subs === []) {
        return [$e];
    }
    $out = [];
    foreach ($subs as $s) {
        array_push($out, ...schema_leaf_errors($s));
    }
    return $out;
}

/** Turn a Opis error into the API's {field, code, message} list. */
function schema_errors_to_list(string $schemaName, ValidationError $error): array
{
    $props     = openapi_spec()->components->schemas->{$schemaName}->properties ?? new stdClass();
    $formatter = new ErrorFormatter();
    $list      = [];

    $friendly = static function (string $field, string $code, string $fallback) use ($props): string {
        return $props->{$field}->{'x-messages'}->{$code} ?? $fallback;
    };

    foreach (schema_leaf_errors($error) as $leaf) {
        $code = $leaf->keyword();
        $args = $leaf->args();

        if ($code === 'required') {
            foreach ((array) ($args['missing'] ?? []) as $missing) {
                $list[] = ['field' => (string) $missing, 'code' => 'required',
                           'message' => $friendly((string) $missing, 'required', 'This field is required.')];
            }
        } elseif ($code === 'additionalProperties') {
            foreach ((array) ($args['properties'] ?? []) as $extra) {
                if (isset($props->{$extra})) {
                    continue;                                      // declared fields are judged by their own keywords
                }
                $list[] = ['field' => (string) $extra, 'code' => 'additionalProperties',
                           'message' => 'Unknown field. Only the fields in the API contract are accepted.'];
            }
        } else {
            $field  = implode('.', array_map('strval', $leaf->data()->fullPath())) ?: '(body)';
            $list[] = ['field' => $field, 'code' => $code,
                       'message' => $friendly($field, $code, $formatter->formatErrorMessage($leaf))];
        }
    }
    return $list;
}

/**
 * Validate a request body against components/schemas/<name>.
 * Returns the cleaned body, or ends the request with 422.
 */
function validate_body_or_422(string $schemaName, stdClass $body): stdClass
{
    $body   = sanitize_by_schema($schemaName, $body);
    $result = openapi_validator()->validate($body, OPENAPI_URI . '#/components/schemas/' . $schemaName);

    if (!$result->isValid()) {
        $errors = schema_errors_to_list($schemaName, $result->error());
        problem(422, 'Validation failed', 'The request body does not match the API schema.', ['errors' => $errors]);
    }
    return $body;
}

/**
 * Validate the query string against the operation's declared parameters.
 * Unknown parameters and invalid values -> 400.
 */
function validate_query_or_400(object $op): array
{
    $declared = [];
    foreach ($op->parameters ?? [] as $p) {
        $ref = $p->{'$ref'} ?? null;
        $def = openapi_deref($p);
        if (($def->in ?? '') === 'query') {
            $declared[$def->name] = ['def' => $def, 'ptr' => is_string($ref) ? $ref : null];
        }
    }

    $unknown = array_values(array_diff(array_keys($_GET), array_keys($declared)));
    if ($unknown !== []) {
        problem(400, 'Bad Request', 'Unexpected query parameter(s): ' . implode(', ', array_map(
            static fn($k) => preg_replace('/[^\w.\-\[\]]/', '?', (string) $k), $unknown)) . '.',
            ['errors' => array_map(static fn($k) => ['field' => (string) $k, 'code' => 'unknownParameter',
                'message' => 'This query parameter is not part of the API contract.'], $unknown)]);
    }

    $clean = [];
    foreach ($declared as $name => $info) {
        if (!array_key_exists($name, $_GET)) {
            if (isset($info['def']->schema->default)) {
                $clean[$name] = $info['def']->schema->default;
            } elseif (!empty($info['def']->required)) {
                problem(400, 'Bad Request', "Query parameter '$name' is required.");
            }
            continue;
        }
        $raw = $_GET[$name];
        if (!is_string($raw)) {
            problem(400, 'Bad Request', "Query parameter '$name' must appear once.");
        }
        $type  = $info['def']->schema->type ?? 'string';
        $value = ($type === 'integer' && preg_match('/^-?\d{1,9}$/', $raw)) ? (int) $raw : $raw;

        $ptr = $info['ptr'] ? substr($info['ptr'], 1) . '/schema' : null;   // "#/components/parameters/Limit" -> ".../schema"
        $res = openapi_validator()->validate($value, OPENAPI_URI . '#' . $ptr);
        if (!$res->isValid()) {
            problem(400, 'Bad Request', "Query parameter '$name' is invalid.", ['errors' => [[
                'field' => $name, 'code' => schema_leaf_errors($res->error())[0]->keyword(),
                'message' => (new ErrorFormatter())->formatErrorMessage(schema_leaf_errors($res->error())[0]),
            ]]]);
        }
        $clean[$name] = $value;
    }
    return $clean;
}
