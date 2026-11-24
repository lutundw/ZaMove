<?php
declare(strict_types=1);

/**
 * Lab 6, Task 1.4 / A10: Server-Side Request Forgery (SSRF) defence for every request the SERVER
 * makes on a user's behalf (payment-gateway callbacks, webhook verification, fetching a logo URL ...).
 *
 *   safe_fetch('https://api.gateway.example/verify/123')
 *
 * Rules, in the order they run:
 *   1. https only (no http, file, gopher, ftp, data ...), no user:password@ in the URL, port 443 only
 *   2. the host must be on the allow-list in ZAMOVE_FETCH_ALLOWED_HOSTS (empty list = refuse everything)
 *   3. the host name is resolved ONCE; EVERY address must be a public address. Loopback, private
 *      (10/8, 172.16/12, 192.168/16), link-local (169.254/16 incl. the 169.254.169.254 cloud-metadata
 *      service), carrier-grade NAT, multicast, reserved, IPv6 ::1, fc00::/7, fe80::/10 and IPv4-mapped IPv6 are refused
 *   4. the connection is pinned to the address that was checked (CURLOPT_RESOLVE), so a DNS answer that
 *      changes between the check and the connect ("DNS rebinding") cannot redirect the request
 *   5. redirects are NOT followed (a 302 to http://169.254.169.254/ would bypass rule 1 to 4)
 *   6. connect/total timeouts and a response size cap
 */

require_once __DIR__ . '/env.php';

class SsrfBlocked extends RuntimeException {}

/** True only for addresses that are routable on the public Internet. */
function ssrf_ip_is_public(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    $bin = inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible forms: judge the embedded IPv4 address.
        if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return ssrf_ip_is_public(inet_ntop(substr($bin, 12)));
        }
        $first = ord($bin[0]);
        if (($first & 0xfe) === 0xfc || ($first === 0xfe && (ord($bin[1]) & 0xc0) === 0x80) || $first === 0xff) {
            return false;                                       // fc00::/7 unique-local, fe80::/10 link-local, ff00::/8 multicast
        }
        if (substr($bin, 0, 8) === "\x20\x01\x0d\xb8\0\0\0\0" || substr($bin, 0, 4) === "\x20\x01\x0d\xb8") {
            return false;                                       // 2001:db8::/32 documentation
        }
    }
    // PHP's flags cover 0/8, 10/8, 127/8, 169.254/16, 172.16/12, 192.168/16, 240/4 and the IPv6 loopback/unspecified.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        [$a, $b] = array_map('intval', explode('.', $ip));
        if ($a === 100 && $b >= 64 && $b <= 127) { return false; }    // 100.64.0.0/10 carrier-grade NAT
        if ($a === 192 && $b === 0)                { return false; }    // 192.0.0.0/24 IETF protocol assignments
        if ($a === 198 && ($b === 18 || $b === 19)) { return false; }  // 198.18.0.0/15 benchmarking
        if ($a >= 224)                             { return false; }    // multicast and reserved
    }
    return true;
}

/** Default resolver: every A and AAAA record of $host. Tests inject their own. */
function ssrf_resolve(string $host): array
{
    $ips = [];
    foreach ((@dns_get_record($host, DNS_A | DNS_AAAA)) ?: [] as $r) {
        $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
    }
    return array_values(array_filter($ips));
}

/**
 * Validate a URL against rules 1 to 3.
 * @return array{host:string, port:int, ip:string, url:string}  the pinned target
 * @throws SsrfBlocked
 */
function ssrf_validate_url(string $url, ?array $allowHosts = null, ?callable $resolver = null): array
{
    $allowHosts ??= array_filter(array_map('strtolower', array_map('trim', explode(',', zm_env('ZAMOVE_FETCH_ALLOWED_HOSTS', '') ?? ''))));
    $p = parse_url($url);
    if ($p === false || !isset($p['scheme'], $p['host'])) {
        throw new SsrfBlocked('malformed URL');
    }
    if (strtolower($p['scheme']) !== 'https') {
        throw new SsrfBlocked('only https is allowed');
    }
    if (isset($p['user']) || isset($p['pass'])) {
        throw new SsrfBlocked('credentials in the URL are not allowed');
    }
    if (isset($p['port']) && (int) $p['port'] !== 443) {
        throw new SsrfBlocked('only port 443 is allowed');
    }
    $host = strtolower(rtrim($p['host'], '.'));
    $host = trim($host, '[]');
    if ($allowHosts === [] || !in_array($host, $allowHosts, true)) {
        throw new SsrfBlocked('host is not on the allow-list');
    }
    $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($resolver ?? 'ssrf_resolve')($host);
    if ($ips === []) {
        throw new SsrfBlocked('host does not resolve');
    }
    foreach ($ips as $ip) {
        if (!ssrf_ip_is_public($ip)) {
            throw new SsrfBlocked('host resolves to a non-public address');
        }
    }
    return ['host' => $host, 'port' => 443, 'ip' => $ips[0], 'url' => $url];
}

/**
 * Fetch an allow-listed https URL safely. Returns [status, body]. Throws SsrfBlocked or RuntimeException.
 */
function safe_fetch(string $url, array $opts = []): array
{
    $t  = ssrf_validate_url($url, $opts['allow_hosts'] ?? null, $opts['resolver'] ?? null);
    $max = (int) ($opts['max_bytes'] ?? 1048576);
    $ch = curl_init($t['url']);
    $ip = str_contains($t['ip'], ':') ? '[' . $t['ip'] . ']' : $t['ip'];
    $body = '';
    curl_setopt_array($ch, [
        CURLOPT_RESOLVE        => ["{$t['host']}:443:$ip"],        // pin the checked address
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,                           // never follow redirects
        CURLOPT_CONNECTTIMEOUT => (int) ($opts['connect_timeout'] ?? 3),
        CURLOPT_TIMEOUT        => (int) ($opts['timeout'] ?? 8),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_WRITEFUNCTION  => static function ($c, $chunk) use (&$body, $max) {
            $body .= $chunk;
            return strlen($body) > $max ? 0 : strlen($chunk);      // returning less than received aborts the transfer
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($ok === false) {
        throw new RuntimeException('fetch failed: ' . $err);
    }
    return [$status, $body];
}
