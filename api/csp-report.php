<?php
declare(strict_types=1);

/**
 * Receives Content-Security-Policy violation reports from browsers (report-uri) and logs one
 * line per report to api/logs/csp.log. It never echoes anything back and ignores bodies over 8 KB.
 * Values are stripped of control characters so a hostile report cannot forge log lines.
 */
require_once __DIR__ . '/lib/security_headers.php';
send_security_headers();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
$raw = file_get_contents('php://input', false, null, 0, 8193);
if ($raw === false || $raw === '' || strlen($raw) > 8192) {
    http_response_code(413);
    exit;
}
$j = json_decode($raw, true, 6);
$r = is_array($j) ? ($j['csp-report'] ?? null) : null;
if (!is_array($r)) {
    http_response_code(400);
    exit;
}
$clean = static fn($v) => substr(preg_replace('/[^\x20-\x7e]/', '?', (string) $v), 0, 200);
$line  = gmdate('c') . ' csp-violation ' . json_encode([
    'directive' => $clean($r['violated-directive'] ?? ''), 'blocked' => $clean($r['blocked-uri'] ?? ''),
    'document'  => $clean($r['document-uri'] ?? ''), 'source' => $clean($r['source-file'] ?? ''),
    'line'      => (int) ($r['line-number'] ?? 0),
], JSON_UNESCAPED_SLASHES) . "\n";
$dir = __DIR__ . '/logs';
if (!is_dir($dir)) {
    @mkdir($dir, 0750, true);
}
$log = $dir . '/csp.log';
if (!is_file($log) || filesize($log) < 1048576) {          // stop writing at 1 MB so reports cannot fill the disk
    @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
}
http_response_code(204);
