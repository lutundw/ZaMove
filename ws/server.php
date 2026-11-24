<?php
declare(strict_types=1);

//windows fix: SIGTERM / SIGINT dont exit on windows
if (!defined('SIGTERM')) define('SIGTERM', 15);
if (!defined('SIGINT')) define('SIGINT', 2);
/**
 * LAB 8 (NEW FILE) - ZaMove WebSocket daemon (RFC 6455), written with PHP's own stream functions so it runs on
 * XAMPP's PHP with no extra packages.  Start it:   php ws/server.php        (leave the window open)
 *
 *  Task 1  HTTP Upgrade handshake; the HttpOnly session cookie is verified BEFORE the 101 is sent. No/invalid
 *          session -> "401 Unauthorized". Wrong Origin -> 403 (blocks cross-site WebSocket hijacking).
 *          Every connected socket joins the room "user:<id>"; an event is delivered only to its recipient.
 *  Task 2  events are rows in realtime_events (written with prepared statements by api/lib/realtime.php).
 *          {"type":"hello","last_event_id":N} replays what the user missed. Incoming frames are checked against
 *          ws/frame_schemas.json (Lab 4 validator) and bounded: 16 KB frame, 30 frames / 10 s per socket.
 *  Task 3  Ping (0x9) every ZAMOVE_WS_PING_SEC; a socket whose Pong (0xA) does not arrive within
 *          ZAMOVE_WS_PONG_TIMEOUT is terminated and removed from every room (no memory leak).
 */
require_once __DIR__ . '/../api/lib/env.php';
require_once __DIR__ . '/../api/lib/session.php';
require_once __DIR__ . '/../api/lib/authz.php';
require_once __DIR__ . '/../api/lib/realtime.php';
require_once __DIR__ . '/../api/vendor/autoload.php';

use Opis\JsonSchema\Validator;

const WS_GUID       = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';        // fixed by RFC 6455 section 1.3
const WS_MAX_FRAME  = 16384;
const WS_MAX_HEADER = 8192;
const WS_RATE_N     = 30;
const WS_RATE_SECS  = 10;
const WS_MAX_PER_USER = 5;
const WS_MAX_TOTAL  = 1000;

$HOST   = zm_env('ZAMOVE_WS_HOST', '127.0.0.1');
$PORT   = (int) zm_env('ZAMOVE_WS_PORT', '8081');
$PING   = max(1, (int) zm_env('ZAMOVE_WS_PING_SEC', '25'));
$TIMEOUT = max(1, (int) zm_env('ZAMOVE_WS_PONG_TIMEOUT', '10'));
$ORIGINS = array_filter(array_map('trim', explode(',', (string) zm_env('ZAMOVE_WS_ALLOWED_ORIGINS', 'https://localhost'))));
$POLL_MS = 300;

function logln(string $m): void { fwrite(STDOUT, gmdate('H:i:s') . '.' . substr(sprintf('%.3f', microtime(true)), -3) . ' ' . $m . "\n"); }

$schema = json_decode((string) file_get_contents(__DIR__ . '/frame_schemas.json'), false, 64, JSON_THROW_ON_ERROR);
$validator = new Validator();

/** @var array<int,array> $conns  id => connection state */
$conns = []; $rooms = []; $nextId = 1;
$cursor = rt_max_event_id(db());                                    // only events created after startup are "live"

$server = @stream_socket_server("tcp://$HOST:$PORT", $errno, $errstr);
if (!$server) { fwrite(STDERR, "cannot listen on $HOST:$PORT: $errstr\n"); exit(1); }
stream_set_blocking($server, false);
logln("ZaMove WebSocket daemon listening on ws://$HOST:$PORT  ping={$PING}s pong-timeout={$TIMEOUT}s origins=" . implode(',', $ORIGINS));

// ---------------------------------------------------------------- frames
function ws_frame(int $opcode, string $payload): string
{
    $len = strlen($payload);
    $h = chr(0x80 | $opcode);                                        // FIN=1 + opcode; server frames are never masked
    if ($len < 126)        { $h .= chr($len); }
    elseif ($len < 65536)  { $h .= chr(126) . pack('n', $len); }
    else                   { $h .= chr(127) . pack('J', $len); }
    return $h . $payload;
}

function send_raw(int $id, string $bytes): void
{
    global $conns;
    if (!isset($conns[$id])) { return; }
    $conns[$id]['out'] .= $bytes;
    flush_out($id);
}

function flush_out(int $id): void
{
    global $conns;
    $c = &$conns[$id];
    while ($c['out'] !== '') {
        $n = @fwrite($c['sock'], $c['out']);
        if ($n === false || $n === 0) { break; }                      // socket buffer full: retry on the next loop
        $c['out'] = substr($c['out'], $n);
    }
    if (strlen($c['out']) > 1048576) { drop($id, 'backpressure: client too slow (1 MB queued)'); }   // slow-consumer protection
}

function send_json(int $id, array $msg): void { send_raw($id, ws_frame(0x1, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))); }

function drop(int $id, string $why, ?int $closeCode = null): void
{
    global $conns, $rooms;
    if (!isset($conns[$id])) { return; }
    $c = $conns[$id];
    if ($closeCode !== null && $c['state'] === 'open') {
        @fwrite($c['sock'], ws_frame(0x8, pack('n', $closeCode)));
    }
    @fclose($c['sock']);
    if ($c['user'] !== null) {
        unset($rooms[$c['user']][$id]);
        if (empty($rooms[$c['user']])) { unset($rooms[$c['user']]); }
    }
    unset($conns[$id]);
    logln("CLOSE conn#$id user=" . ($c['user'] ?? '-') . " reason=\"$why\" open_sockets=" . count($conns));
}

// ---------------------------------------------------------------- handshake
function reject(int $id, int $status, string $title, string $detail): void
{
    global $conns;
    $reason = [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 426 => 'Upgrade Required', 503 => 'Service Unavailable'][$status] ?? 'Error';
    $body = json_encode(['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail]);
    @fwrite($conns[$id]['sock'], "HTTP/1.1 $status $reason\r\nContent-Type: application/problem+json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
    logln("REJECT conn#$id HTTP $status $title ({$detail})");
    drop($id, "handshake rejected $status");
}

function handshake(int $id, string $raw): void
{
    global $conns, $ORIGINS, $rooms;
    $lines = explode("\r\n", $raw);
    if (!preg_match('#^GET (\S+) HTTP/1\.1$#', array_shift($lines), $m)) { reject($id, 400, 'Bad Request', 'Expected GET ... HTTP/1.1'); return; }
    $h = [];
    foreach ($lines as $l) { if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $h[strtolower(trim($k))] = trim($v); } }

    if (strtolower($h['upgrade'] ?? '') !== 'websocket' || !str_contains(strtolower($h['connection'] ?? ''), 'upgrade')) { reject($id, 426, 'Upgrade Required', 'This endpoint speaks WebSocket only.'); return; }
    if (($h['sec-websocket-version'] ?? '') !== '13') { reject($id, 400, 'Bad Request', 'Sec-WebSocket-Version must be 13.'); return; }
    $key = $h['sec-websocket-key'] ?? '';
    if (strlen((string) base64_decode($key, true)) !== 16) { reject($id, 400, 'Bad Request', 'Invalid Sec-WebSocket-Key.'); return; }

    // CSWSH: browsers always send Origin; refuse pages that are not ours.
    if (isset($h['origin']) && !in_array($h['origin'], $ORIGINS, true)) { reject($id, 403, 'Forbidden', 'Origin not allowed.'); return; }

    // ---- AUTHENTICATION (Lab 5 session cookie), decided before the 101 response ----
    $_COOKIE = [];
    foreach (explode(';', $h['cookie'] ?? '') as $pair) { if (str_contains($pair, '=')) { [$k, $v] = explode('=', $pair, 2); $_COOKIE[trim($k)] = trim($v); } }
    $user = null;
    try { $user = zm_session_user(); } catch (Throwable $e) { logln('db error during handshake: ' . $e->getMessage()); reject($id, 503, 'Service Unavailable', 'Try again shortly.'); return; }
    if ($user === null) { reject($id, 401, 'Unauthorized', 'No valid session cookie.'); return; }
    $uid = (int) $user['user_id'];
    if (count($rooms[$uid] ?? []) >= WS_MAX_PER_USER) { reject($id, 503, 'Service Unavailable', 'Too many open connections for this account.'); return; }

    $accept = base64_encode(sha1($key . WS_GUID, true));
    @fwrite($conns[$id]['sock'], "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
    $conns[$id]['state'] = 'open'; $conns[$id]['user'] = $uid; $conns[$id]['role'] = $user['role']; $conns[$id]['name'] = $user['full_name'];
    $conns[$id]['cookie'] = $_COOKIE[ZM_COOKIE] ?? ''; $conns[$id]['ready'] = false; $conns[$id]['sent'] = 0;
    $conns[$id]['lastPong'] = microtime(true); $conns[$id]['pingAt'] = microtime(true); $conns[$id]['awaiting'] = null;
    $rooms[$uid][$id] = true;                                         // join room user:<id>
    logln("CONNECT conn#$id user=$uid role={$user['role']} 101 Switching Protocols room=user:$uid open_sockets=" . count($conns));
    send_json($id, ['type' => 'welcome', 'user_id' => $uid, 'role' => $user['role'], 'ping_sec' => $GLOBALS['PING']]);
}

// ---------------------------------------------------------------- application messages
function handle_text(int $id, string $text): void
{
    global $conns, $schema, $validator, $cursor;
    $c = &$conns[$id];
    $now = time();
    $c['rate'] = array_values(array_filter($c['rate'] ?? [], fn($t) => $t > $now - WS_RATE_SECS));
    if (count($c['rate']) >= WS_RATE_N) { send_json($id, ['type' => 'error', 'detail' => 'Slow down: too many messages.']); logln("RATELIMIT conn#$id"); return; }
    $c['rate'][] = $now;

    try { $data = json_decode($text, false, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { send_json($id, ['type' => 'error', 'detail' => 'Frame is not valid JSON.']); logln("BADFRAME conn#$id not JSON"); return; }
    if (!($data instanceof stdClass)) { send_json($id, ['type' => 'error', 'detail' => 'Frame must be a JSON object.']); return; }
    $res = $validator->validate($data, $schema);
    if (!$res->isValid()) { send_json($id, ['type' => 'error', 'detail' => 'Frame does not match the schema.']); logln("BADFRAME conn#$id schema violation (type=" . (is_string($data->type ?? null) ? substr($data->type, 0, 20) : '?') . ')'); return; }

    $pdo = db();
    $user = ['user_id' => $c['user'], 'role' => $c['role'], 'full_name' => $c['name']];
    switch ($data->type) {
        case 'hello':
            // bring the cursor up to date first, so replay and live delivery cannot overlap
            fanout();
            $rows = rt_replay($pdo, (int) $c['user'], (int) ($data->last_event_id ?? 0));
            foreach ($rows as $ev) { send_json($id, $ev); $c['sent'] = max($c['sent'], $ev['event_id']); }
            $c['sent'] = max($c['sent'], (int) ($data->last_event_id ?? 0), $cursor);
            $c['ready'] = true;
            send_json($id, ['type' => 'sync_done', 'replayed' => count($rows)]);
            logln("SYNC conn#$id user={$c['user']} last_event_id=" . (int) ($data->last_event_id ?? 0) . ' replayed=' . count($rows));
            break;
        case 'message':
            $r = rt_send_message($pdo, $user, (int) $data->booking_id, $data->text);
            send_json($id, $r['ok'] ? ['type' => 'ok', 'detail' => 'Message sent.'] : ['type' => 'error', 'detail' => $r['detail']]);
            break;
        case 'status':
            if (!in_array($c['role'], ['operator', 'admin'], true)) { send_json($id, ['type' => 'error', 'detail' => 'Only operators can change a booking status.']); logln("DENIED conn#$id status change by role={$c['role']}"); break; }
            $r = rt_change_status($pdo, $user, (int) $data->booking_id, $data->status);
            send_json($id, $r['ok'] ? ['type' => 'ok', 'detail' => 'Status updated.'] : ['type' => 'error', 'detail' => $r['detail']]);
            break;
        case 'ping':
            send_json($id, ['type' => 'pong', 't' => microtime(true)]);
            break;
    }
}

/** Deliver rows written since the last look (by the HTTP API or by another socket) to their recipient's sockets. */
function fanout(): void
{
    global $cursor, $rooms, $conns;
    foreach (rt_new_events(db(), $cursor) as $r) {
        $cursor = max($cursor, (int) $r['event_id']);
        foreach (array_keys($rooms[(int) $r['user_id']] ?? []) as $cid) {
            if ($conns[$cid]['ready'] && (int) $r['event_id'] > $conns[$cid]['sent']) {
                send_json($cid, rt_row_to_event($r));
                $conns[$cid]['sent'] = (int) $r['event_id'];
                logln("PUSH event#{$r['event_id']} {$r['event_type']} -> room user:{$r['user_id']} conn#$cid");
            }
        }
    }
}

// ---------------------------------------------------------------- frame parser
function parse_frames(int $id): void
{
    global $conns;
    $c = &$conns[$id];
    while (isset($conns[$id])) {
        $b = $c['in']; $n = strlen($b);
        if ($n < 2) { return; }
        $b0 = ord($b[0]); $b1 = ord($b[1]);
        $fin = ($b0 & 0x80) !== 0; $op = $b0 & 0x0F; $masked = ($b1 & 0x80) !== 0; $len = $b1 & 0x7F; $off = 2;
        if (($b0 & 0x70) !== 0) { drop($id, 'reserved bits set', 1002); return; }
        if (!$masked) { drop($id, 'client frame not masked (RFC 6455 5.1)', 1002); return; }
        if ($len === 126) { if ($n < 4) { return; } $len = unpack('n', substr($b, 2, 2))[1]; $off = 4; }
        elseif ($len === 127) { if ($n < 10) { return; } $len = unpack('J', substr($b, 2, 8))[1]; $off = 10; }
        if ($len > WS_MAX_FRAME || $len < 0) { drop($id, "frame too large ($len bytes)", 1009); return; }
        if ($op >= 0x8 && (!$fin || $len > 125)) { drop($id, 'bad control frame', 1002); return; }
        if ($n < $off + 4 + $len) { return; }
        $mask = substr($b, $off, 4); $payload = substr($b, $off + 4, $len);
        $c['in'] = substr($b, $off + 4 + $len);
        for ($i = 0; $i < $len; $i++) { $payload[$i] = $payload[$i] ^ $mask[$i % 4]; }

        switch ($op) {
            case 0x9: send_raw($id, ws_frame(0xA, $payload)); break;                         // client Ping -> Pong
            case 0xA:                                                                           // Pong: the client is alive
                $c['lastPong'] = microtime(true);
                if ($c['awaiting'] !== null) { logln(sprintf('PONG conn#%d user=%d rtt=%.0f ms', $id, $c['user'], ($c['lastPong'] - $c['awaiting']) * 1000)); }
                $c['awaiting'] = null; break;
            case 0x8: drop($id, 'client closed (code ' . (strlen($payload) >= 2 ? unpack('n', $payload)[1] : 1005) . ')', 1000); return;
            case 0x1: case 0x0:
                if ($op === 0x1) { $c['frag'] = ''; }
                $c['frag'] = ($c['frag'] ?? '') . $payload;
                if (strlen($c['frag']) > WS_MAX_FRAME) { drop($id, 'message too large', 1009); return; }
                if ($fin) {
                    $msg = $c['frag']; $c['frag'] = '';
                    if (!mb_check_encoding($msg, 'UTF-8')) { drop($id, 'invalid UTF-8 in text frame', 1007); return; }
                    handle_text($id, $msg);
                }
                break;
            default: drop($id, 'unsupported opcode ' . $op, 1003); return;                     // binary frames are not part of our protocol
        }
    }
}

// ---------------------------------------------------------------- main loop
$lastPoll = 0.0; $lastBeat = microtime(true);
foreach ([SIGTERM, SIGINT] as $sig) {
    if (function_exists('pcntl_signal')) { pcntl_signal($sig, function () use (&$conns) { logln('shutting down: closing ' . count($conns) . ' socket(s) with 1001 Going Away'); foreach (array_keys($conns) as $i) { drop($i, 'server shutdown', 1001); } exit(0); }); }
}
while (true) {
    if (function_exists('pcntl_signal_dispatch')) { pcntl_signal_dispatch(); }
    $read = [$server]; $write = [];
    foreach ($conns as $c) { $read[] = $c['sock']; if ($c['out'] !== '') { $write[] = $c['sock']; } }
    $except = null;
    if (@stream_select($read, $write, $except, 0, 100000) === false) { continue; }

    foreach ($write as $s) { foreach ($conns as $i => $c) { if ($c['sock'] === $s) { flush_out($i); } } }
    foreach ($read as $s) {
        if ($s === $server) {
            $sock = @stream_socket_accept($server, 0, $peer);
            if (!$sock) { continue; }
            if (count($conns) >= WS_MAX_TOTAL) { @fclose($sock); logln('REJECT too many sockets'); continue; }
            stream_set_blocking($sock, false);
            $id = $nextId++;
            $conns[$id] = ['sock' => $sock, 'state' => 'http', 'in' => '', 'out' => '', 'user' => null, 'since' => microtime(true), 'peer' => $peer];
            continue;
        }
        $id = null; foreach ($conns as $i => $c) { if ($c['sock'] === $s) { $id = $i; break; } }
        if ($id === null) { continue; }
        $data = @fread($s, 65536);
        if ($data === '' || $data === false) { if (feof($s)) { drop($id, 'TCP closed by peer'); } continue; }
        $conns[$id]['in'] .= $data;
        if ($conns[$id]['state'] === 'http') {
            if (strlen($conns[$id]['in']) > WS_MAX_HEADER) { reject($id, 400, 'Bad Request', 'Header too large.'); continue; }
            if (($end = strpos($conns[$id]['in'], "\r\n\r\n")) !== false) {
                $raw = substr($conns[$id]['in'], 0, $end);
                $conns[$id]['in'] = substr($conns[$id]['in'], $end + 4);
                handshake($id, $raw);
                if (isset($conns[$id]) && $conns[$id]['in'] !== '') { parse_frames($id); }
            }
        } else {
            if (strlen($conns[$id]['in']) > 2 * WS_MAX_FRAME) { drop($id, 'input buffer overflow', 1009); continue; }
            parse_frames($id);
        }
    }

    $t = microtime(true);
    foreach ($conns as $i => $c) {                                    // half-open HTTP: never finished the handshake within 5 s
        if ($c['state'] === 'http' && $t - $c['since'] > 5) { drop($i, 'handshake timeout'); }
    }
    if (($t - $lastPoll) * 1000 >= $POLL_MS) {                        // push new events
        $lastPoll = $t;
        try { fanout(); } catch (Throwable $e) { logln('fanout error: ' . $e->getMessage()); }
    }
    foreach ($conns as $i => $c) {                                    // ---- Task 3: heartbeat ----
        if ($c['state'] !== 'open') { continue; }
        if ($c['awaiting'] !== null && $t - $c['awaiting'] > $TIMEOUT) {
            logln(sprintf('EVICT conn#%d user=%d: no Pong for %.1f s (timeout %d s) -> terminating half-open socket', $i, $c['user'], $t - $c['awaiting'], $TIMEOUT));
            drop($i, 'heartbeat timeout', 1001);
        } elseif ($c['awaiting'] === null && $t - $c['pingAt'] >= $PING) {
            $conns[$i]['pingAt'] = $t; $conns[$i]['awaiting'] = $t;
            send_raw($i, ws_frame(0x9, 'zm'));
            logln("PING conn#$i user={$c['user']}");
            $_COOKIE = [ZM_COOKIE => $c['cookie']];                   // logout / expiry elsewhere closes the socket too
            if (zm_session_user() === null) { drop($i, 'session ended', 4401); }
        }
    }
}
