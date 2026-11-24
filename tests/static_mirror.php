<?php
/**
 * LAB 9 (NEW, SANDBOX ONLY). PHP's built-in test server has no mod_headers / mod_deflate, so this file reproduces what the
 * LAB 9 rules in .htaccess do for static files, to let curl and the browser show the SAME headers Apache will send.
 * On XAMPP this file is never used (Apache reads .htaccess). Enabled by ZAMOVE_STATIC_MIRROR=1.
 */
function static_mirror(string $docroot, string $path): bool
{
    $file = realpath($docroot . $path);
    if ($file === false || !str_starts_with($file, realpath($docroot) . DIRECTORY_SEPARATOR) || !is_file($file)) {
        return false;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = ['js' => 'application/javascript', 'css' => 'text/css', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif',
              'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'json' => 'application/json'];
    if (!isset($types[$ext])) { return false; }
    $hashed = preg_match('/\.[0-9a-f]{8}\.(js|css|avif|webp|png|jpg)$/', $file) === 1;
    $ae = strtolower($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
    $send = $file; $enc = '';
    if (in_array($ext, ['js', 'css'], true)) {
        if (str_contains($ae, 'br') && is_file($file . '.br')) { $send = $file . '.br'; $enc = 'br'; }
        elseif (str_contains($ae, 'gzip') && is_file($file . '.gz')) { $send = $file . '.gz'; $enc = 'gzip'; }
    }
    $size = filesize($send); $etag = '"' . dechex(filemtime($send)) . '-' . dechex($size) . ($enc ? '-' . $enc : '') . '"';
    header('Content-Type: ' . $types[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($send)) . ' GMT');
    header('Cache-Control: ' . ($hashed ? 'public, max-age=31536000, immutable' : 'public, max-age=86400'));
    header('Accept-Ranges: bytes');
    if ($enc) { header('Content-Encoding: ' . $enc); }
    if (in_array($ext, ['js', 'css'], true) || $enc) { header('Vary: Accept-Encoding'); }
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); return true; }
    $start = 0; $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); } else { $start = (int) $m[1]; if ($m[2] !== '') { $end = min($end, (int) $m[2]); } }
        if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); return true; }
        http_response_code(206); header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') { return true; }
    $fh = fopen($send, 'rb'); fseek($fh, $start); echo fread($fh, $end - $start + 1); fclose($fh);
    return true;
}
