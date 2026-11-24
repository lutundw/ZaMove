<?php
/** Lab 6 unit tests that need no web server: SSRF guard, environment validation, authorisation matrix, output encoding rules. */
require __DIR__ . '/../api/lib/safe_fetch.php';
$pass = $fail = 0;
function ok(string $name, bool $cond): void { global $pass, $fail; echo ($cond ? "RESULT: PASS" : "RESULT: FAIL") . " ($name)\n"; $cond ? $pass++ : $fail++; }

echo "=== A10 SSRF: address classification (ssrf_ip_is_public)\n";
foreach (['127.0.0.1','127.1.2.3','10.0.0.5','172.16.0.1','172.31.255.254','192.168.1.1','169.254.169.254','169.254.0.1','0.0.0.0','100.64.0.1','198.18.0.1','224.0.0.1','255.255.255.255',
          '::1','::','fc00::1','fd12:3456::1','fe80::1','::ffff:127.0.0.1','::ffff:10.1.1.1','::ffff:169.254.169.254','2001:db8::1','not-an-ip','999.1.1.1'] as $ip) {
    ok("blocked $ip", !ssrf_ip_is_public($ip));
}
foreach (['8.8.8.8','1.1.1.1','93.184.216.34','172.15.0.1','172.32.0.1','2606:4700:4700::1111','::ffff:8.8.8.8'] as $ip) { ok("allowed $ip", ssrf_ip_is_public($ip)); }

echo "=== A10 SSRF: URL validation (ssrf_validate_url) with allow-list api.gateway.example\n";
$allow = ['api.gateway.example'];
$dns = fn(array $ips) => fn(string $h) => $ips;
$cases = [
 ['https://api.gateway.example/verify/1', ['93.184.216.34'], true,  'allowed host resolving to a public address'],
 ['http://api.gateway.example/verify/1',  ['93.184.216.34'], false, 'plain http refused'],
 ['file:///etc/passwd',                   [],                false, 'file:// refused'],
 ['gopher://api.gateway.example/',        ['93.184.216.34'], false, 'gopher:// refused'],
 ['https://evil.example/',                ['93.184.216.34'], false, 'host not on the allow-list'],
 ['https://api.gateway.example@evil.example/', ['93.184.216.34'], false, 'user@host trick refused'],
 ['https://user:pw@api.gateway.example/', ['93.184.216.34'], false, 'credentials in URL refused'],
 ['https://api.gateway.example:8443/',    ['93.184.216.34'], false, 'non-443 port refused'],
 ['https://api.gateway.example/',         ['169.254.169.254'], false, 'allow-listed name that resolves to the cloud metadata address'],
 ['https://api.gateway.example/',         ['93.184.216.34','10.0.0.9'], false, 'one private address among several records (DNS rebinding mix)'],
 ['https://api.gateway.example/',         ['127.0.0.1'],     false, 'allow-listed name that resolves to loopback'],
 ['https://api.gateway.example/',         ['::ffff:192.168.0.1'], false, 'IPv4-mapped IPv6 private address'],
 ['https://api.gateway.example/',         [],                false, 'name that does not resolve'],
 ['https://169.254.169.254/latest/meta-data/', [],           false, 'metadata IP literal (not allow-listed)'],
 ['https:///nohost',                      [],                false, 'malformed URL'],
];
foreach ($cases as [$url, $ips, $expectOk, $label]) {
    try { ssrf_validate_url($url, $allow, $dns($ips)); $res = true; } catch (SsrfBlocked $e) { $res = false; }
    ok($label . ' -> ' . ($expectOk ? 'allowed' : 'blocked'), $res === $expectOk);
}
try { ssrf_validate_url('https://8.8.8.8/', [], null); $r = true; } catch (SsrfBlocked $e) { $r = false; }
ok('empty allow-list refuses everything', $r === false);
try { safe_fetch('https://127.0.0.1/admin', ['allow_hosts' => ['127.0.0.1']]); $r = 'fetched'; } catch (SsrfBlocked $e) { $r = 'blocked'; }
ok('safe_fetch refuses an allow-listed loopback address before any connection is made', $r === 'blocked');

echo "=== A02/A05 environment validation (tools/check_env.php)\n";
$php = escapeshellarg(PHP_BINARY); $tool = escapeshellarg(__DIR__ . '/../tools/check_env.php');
$run = function (array $env) use ($php, $tool): array {
    $pre = ''; foreach ($env as $k => $v) { $pre .= "$k=" . escapeshellarg($v) . ' '; }
    exec("env -i PATH=\"\$PATH\" ZAMOVE_ENV_FILE=/nonexistent $pre $php $tool 2>&1", $out, $rc); return [$rc, implode("\n", $out)];
};
$good = ['ZAMOVE_DB_DRIVER' => 'sqlite', 'ZAMOVE_APP_KEY' => bin2hex(random_bytes(32))];
[$rc, $o] = $run($good);                                                      ok("complete configuration passes (exit $rc)", $rc === 0);
[$rc, $o] = $run(['ZAMOVE_DB_DRIVER' => 'sqlite']);                           ok("missing ZAMOVE_APP_KEY halts with exit 1: " . trim(strtok($o, "\n") . ' / ' . (explode("\n", $o)[1] ?? '')), $rc === 1 && str_contains($o, 'ZAMOVE_APP_KEY is missing'));
[$rc, $o] = $run(['ZAMOVE_DB_DRIVER' => 'sqlite', 'ZAMOVE_APP_KEY' => 'short']); ok("weak (short) ZAMOVE_APP_KEY refused", $rc === 1 && str_contains($o, 'too short'));
[$rc, $o] = $run(['ZAMOVE_DB_DRIVER' => 'mysql', 'ZAMOVE_APP_KEY' => bin2hex(random_bytes(32))]); ok("mysql driver without host/name/user/pass refused", $rc === 1 && str_contains($o, 'ZAMOVE_DB_HOST is missing') && str_contains($o, 'ZAMOVE_DB_PASS is missing'));
[$rc, $o] = $run(['ZAMOVE_DB_DRIVER' => 'mysql', 'ZAMOVE_APP_KEY' => bin2hex(random_bytes(32)), 'ZAMOVE_ENV' => 'production', 'ZAMOVE_DB_HOST' => 'localhost', 'ZAMOVE_DB_NAME' => 'zamumove', 'ZAMOVE_DB_USER' => 'root', 'ZAMOVE_DB_PASS' => '']);
ok("production with DB user root and empty password refused", $rc === 1 && str_contains($o, 'must not be root'));
[$rc, $o] = $run(['ZAMOVE_DB_DRIVER' => 'sqlite', 'ZAMOVE_APP_KEY' => 'changeme' . str_repeat('x', 30)]); ok("placeholder secret value refused", $rc === 1 && str_contains($o, 'placeholder'));
ok("error output never contains a secret value", !str_contains($o, 'xxxxxxxx'));

echo "=== A01 authorisation matrix (authz_can_view_booking, no database needed for admin/customer)\n";
require __DIR__ . '/../api/lib/authz.php';
$bk = ['customer_id' => 7, 'operator_id' => 3];
ok('admin may view any booking', authz_can_view_booking(['role' => 'admin', 'user_id' => 1], $bk));
ok('customer may view own booking', authz_can_view_booking(['role' => 'customer', 'user_id' => 7], $bk));
ok('customer may NOT view another customer\'s booking', !authz_can_view_booking(['role' => 'customer', 'user_id' => 8], $bk));
ok('unknown role is denied by default', !authz_can_view_booking(['role' => 'guest', 'user_id' => 7], $bk));
ok('missing role is denied by default', !authz_can_view_booking(['user_id' => 7], $bk));
echo "SUMMARY: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
