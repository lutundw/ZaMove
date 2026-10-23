<?php
declare(strict_types=1);

/**
 * RFC 7807 "Problem Details for HTTP APIs" responses
 * (obsoleted by RFC 9457, which keeps the same members).
 *
 * Every error leaves the API as:
 *   Content-Type: application/problem+json
 *   { "type", "title", "status", "detail", "instance", ...extensions }
 * Validation failures add an "errors" extension: a list of
 *   { "field", "code", "message" }.
 */

const PROBLEM_BASE = 'https://zamove.example/problems/';

function problem_slug(int $status): string
{
    return match ($status) {
        400 => 'bad-request',
        405 => 'method-not-allowed',
        409 => 'conflict',
        413 => 'payload-too-large',
        415 => 'unsupported-media-type',
        422 => 'validation-error',
        default => 'server-error',
    };
}

/** Send the status line with a proper reason phrase (PHP has none for 422 / 413). */
function send_status(int $status): void
{
    $phrases = [
        200 => 'OK', 201 => 'Created', 400 => 'Bad Request', 405 => 'Method Not Allowed',
        409 => 'Conflict', 413 => 'Payload Too Large', 415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity', 500 => 'Internal Server Error',
    ];
    $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
    header("$protocol $status " . ($phrases[$status] ?? 'Error'), true, $status);
}

/** Send a problem+json response and stop the script. */
function problem(int $status, string $title, string $detail, array $extra = [], array $headers = []): never
{
    send_status($status);
    header('Content-Type: application/problem+json; charset=utf-8');
    foreach ($headers as $h) {
        header($h);
    }
    $body = [
        'type'     => PROBLEM_BASE . problem_slug($status),
        'title'    => $title,
        'status'   => $status,
        'detail'   => $detail,
        'instance' => strtok($_SERVER['REQUEST_URI'] ?? '/', '?'),
    ] + $extra;

    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/** Send a normal JSON success response and stop the script. */
function json_response(int $status, array $data): never
{
    send_status($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
