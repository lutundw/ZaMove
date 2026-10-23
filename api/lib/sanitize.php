<?php
declare(strict_types=1);

/**
 * Sanitisation helpers (run BEFORE validation, on the server).
 *
 * Sanitising is the second guard. The first is the allow-list pattern
 * each field must match, and the third is output encoding when data is
 * shown again (textContent in JavaScript, htmlspecialchars() in PHP).
 * SQL injection is stopped separately by prepared statements.
 */

/** Remove NUL/control characters, strip HTML/PHP tags, collapse whitespace. */
function sanitize_text(string $value): string
{
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($n !== false) {
            $value = $n;
        }
    }
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';   // control chars (also stops header/CRLF injection)
    $value = strip_tags($value);                                       // <script>, <b>, <img onerror=...> ...
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    return trim($value);
}

/** Email: sanitise like text, drop spaces, lower-case. */
function sanitize_email(string $value): string
{
    $value = sanitize_text($value);
    $value = str_replace(' ', '', $value);
    return mb_strtolower($value, 'UTF-8');
}

/** Phone: keep digits and a leading +, drop spaces, hyphens and brackets. */
function sanitize_phone(string $value): string
{
    $value = sanitize_text($value);
    return preg_replace('/[\s().\-]+/', '', $value) ?? '';
}

/** Passwords are never altered (changing them would change what the user typed). */
function sanitize_none(string $value): string
{
    return $value;
}
