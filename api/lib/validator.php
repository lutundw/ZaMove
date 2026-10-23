<?php
declare(strict_types=1);

require_once __DIR__ . '/sanitize.php';

/**
 * Schema-driven validation for JSON request bodies.
 *
 * The schema is a small JSON-Schema-style array: for every allowed field
 * it lists type, required, sanitiser, length limits, enum, regex and
 * extra rules. Anything NOT in the schema is rejected
 * (additionalProperties: false), which blocks mass-assignment attacks
 * such as {"role":"admin"} or {"account_status":"active"}.
 *
 * The messages here deliberately match the client-side messages in
 * js/validation.js so the user sees the same wording either way.
 */

const REGISTER_SCHEMA = [
    'role' => [
        'required' => true,
        'sanitize' => 'text',
        'enum'     => ['customer', 'operator'],          // 'admin' can never self-register
        'messages' => [
            'required' => 'Choose Passenger or Bus operator.',
            'enum'     => 'Choose Passenger or Bus operator.',
        ],
    ],
    'full_name' => [
        'required' => true,
        'sanitize' => 'text',
        'min'      => 2,
        'max'      => 120,
        'pattern'  => '/^[\p{L}\p{N}][\p{L}\p{N} .\'&\-]*$/u',
        'messages' => [
            'required' => 'Enter your full name or company name.',
            'min'      => 'Name must be at least 2 characters.',
            'max'      => 'Name must be 120 characters or fewer.',
            'pattern'  => 'Use letters, numbers, spaces and . \' & - only.',
        ],
    ],
    'email' => [
        'required' => true,
        'sanitize' => 'email',
        'max'      => 150,
        'format'   => 'email',
        'messages' => [
            'required' => 'Enter your email address.',
            'max'      => 'Email must be 150 characters or fewer.',
            'format'   => 'Enter a valid email address, for example name@example.com.',
        ],
    ],
    'phone' => [
        'required' => true,
        'sanitize' => 'phone',
        // Zambian mobile: +260 or 0, then 7 or 9, then 8 more digits.
        'pattern'  => '/^(?:\+260|0)[79]\d{8}$/',
        'messages' => [
            'required' => 'Enter your phone number.',
            'pattern'  => 'Enter a Zambian mobile number, for example 0971234567 or +260971234567.',
        ],
    ],
    'password' => [
        'required' => true,
        'sanitize' => 'none',
        'min'      => 8,
        'max_bytes'=> 72,                                // bcrypt ignores everything after 72 bytes
        'password' => true,
        'messages' => [
            'required'  => 'Create a password.',
            'min'       => 'Password must be at least 8 characters.',
            'max_bytes' => 'Password must be 72 bytes or fewer.',
            'password'  => 'Password needs a lowercase letter, an uppercase letter and a number.',
        ],
    ],
];

/**
 * Validate and sanitise $body against $schema.
 *
 * @return array{0: array<string,string>, 1: list<array{field:string,code:string,message:string}>}
 *         [cleanValues, errors]
 */
function validate_body(array $schema, array $body): array
{
    $errors = [];
    $clean  = [];

    // 1. Unknown fields are an error, not silently ignored.
    foreach (array_keys($body) as $key) {
        if (!array_key_exists((string) $key, $schema)) {
            $errors[] = [
                'field'   => (string) $key,
                'code'    => 'unexpected_field',
                'message' => 'This field is not accepted.',
            ];
        }
    }

    // 2. Each declared field.
    foreach ($schema as $field => $rule) {
        $msg = $rule['messages'] ?? [];
        $fail = function (string $code, ?string $fallback = null) use (&$errors, $field, $msg): void {
            $errors[] = ['field' => $field, 'code' => $code, 'message' => $msg[$code] ?? $fallback ?? 'Invalid value.'];
        };

        if (!array_key_exists($field, $body) || $body[$field] === null) {
            if (!empty($rule['required'])) {
                $fail('required');
            }
            continue;
        }

        // Type check BEFORE touching the value: arrays, objects, numbers, booleans are rejected.
        if (!is_string($body[$field])) {
            $fail('type', 'Must be a text value.');
            continue;
        }

        $sanitizer = 'sanitize_' . ($rule['sanitize'] ?? 'text');
        $value     = $sanitizer($body[$field]);

        if ($value === '') {
            if (!empty($rule['required'])) {
                $fail('required');
            }
            continue;
        }

        if (isset($rule['min']) && mb_strlen($value, 'UTF-8') < $rule['min']) {
            $fail('min');
            continue;
        }
        if (isset($rule['max']) && mb_strlen($value, 'UTF-8') > $rule['max']) {
            $fail('max');
            continue;
        }
        if (isset($rule['max_bytes']) && strlen($value) > $rule['max_bytes']) {
            $fail('max_bytes');
            continue;
        }
        if (isset($rule['enum']) && !in_array($value, $rule['enum'], true)) {
            $fail('enum');
            continue;
        }
        if (isset($rule['pattern']) && preg_match($rule['pattern'], $value) !== 1) {
            $fail('pattern');
            continue;
        }
        if (($rule['format'] ?? '') === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $fail('format');
            continue;
        }
        if (!empty($rule['password'])
            && (preg_match('/[a-z]/', $value) !== 1
                || preg_match('/[A-Z]/', $value) !== 1
                || preg_match('/\d/', $value) !== 1)) {
            $fail('password');
            continue;
        }

        $clean[$field] = $value;
    }

    return [$clean, $errors];
}

/** Store phone numbers in one format: +260XXXXXXXXX. */
function normalise_zm_phone(string $phone): string
{
    return str_starts_with($phone, '0') ? '+260' . substr($phone, 1) : $phone;
}
