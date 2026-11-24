<?php
/**
 * Local secret scanner (Lab 6, Task 4.2). A small stand-in for TruffleHog, which cannot be installed in
 * the build environment. Run TruffleHog yourself too (see INTEGRATION notes):
 *     trufflehog filesystem .          trufflehog git file://. --only-verified
 *
 *   php tools/secret_scan.php [path]            scan the working tree
 *   php tools/secret_scan.php --git [path]      scan every line ADDED in the whole git history
 *   Exit code 1 when anything is found. Findings never print the secret itself, only a masked prefix.
 *
 * A line can be waived with the comment  secret-scan:allow  (used only for documented test fixtures).
 */
$args = array_slice($argv, 1);
$git  = in_array('--git', $args, true);
$root = realpath(end($args) ?: '.') ?: '.';
if ($root === realpath('.') && in_array(end($args), ['--git', false], true)) { $root = realpath('.'); }

$rules = [
    'AWS access key id'          => '/\b(AKIA|ASIA)[0-9A-Z]{16}\b/',
    'Private key block'          => '/-----BEGIN (RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----/',
    'JSON Web Token'             => '/\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
    'Slack / GitHub / Stripe token' => '/\b(xox[baprs]-[A-Za-z0-9-]{10,}|gh[pousr]_[A-Za-z0-9]{30,}|sk_live_[A-Za-z0-9]{16,})\b/',
    'Hard-coded DB credential'   => '/new\s+PDO\([^)]*,\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"][^\'"]*[\'"]/',
    'Hard-coded default password'=> '/[\'"]?(pass(word)?|pwd|secret|api[_-]?key|token)[\'"]?\s*(=>|=|:)\s*[\'"][^\'"$\s{}]{4,}[\'"]/i',
    'Credential default in env lookup' => '/getenv\([\'"][A-Z_]*(PASS|SECRET|KEY|TOKEN)[A-Z_]*[\'"]\)\s*\?:\s*[\'"][^\'"]+[\'"]/',
    'Account with empty password default' => '/getenv\([\'"][A-Z_]*USER[\'"]\)\s*\?:\s*[\'"]root[\'"]/',
];
$skipDirs = ['.git', 'vendor', 'node_modules', 'evidence', 'logs'];
$skipExt  = ['png', 'jpg', 'jpeg', 'gif', 'ico', 'pdf', 'zip', 'woff', 'woff2'];
$skipFiles = ['swagger-ui-bundle.js', 'swagger-ui.css', 'secret_scan.php'];     // third-party bundle / this file's own patterns

function entropy(string $s): float { $f = count_chars($s, 1); $n = strlen($s); $h = 0.0; foreach ($f as $c) { $p = $c / $n; $h -= $p * log($p, 2); } return $h; }
function mask(string $s): string { return substr($s, 0, 4) . str_repeat('*', max(0, min(12, strlen($s) - 4))) . ' (' . strlen($s) . ' chars)'; }

$ignore = [];                                   // paths waived in .secretscanignore (documented fixtures only)
if (is_file($root . '/.secretscanignore')) {
    $ignore = array_values(array_filter(array_map('trim', file($root . '/.secretscanignore')), fn($l) => $l !== '' && $l[0] !== '#'));
    echo "secret_scan: waived paths (.secretscanignore): " . implode(', ', $ignore) . "\n";
}
$found = [];
$scanLine = function (string $where, int $no, string $line) use ($rules, &$found) {
    if (str_contains($line, 'secret-scan:allow')) { return; }
    foreach ($rules as $name => $re) {
        if (preg_match($re, $line, $m)) { $found[] = [$where, $no, $name, mask(trim($m[0]))]; }
    }
    if (preg_match_all('/[\'"=:\s]([A-Za-z0-9+\/_\-]{32,})[\'"\s;,]?/', $line, $mm)) {       // long random-looking strings
        foreach ($mm[1] as $tok) {
            if (entropy($tok) > 4.3 && !preg_match('/^[a-z]+([_\-][a-z]+)*$/i', $tok) && !preg_match('#^[\w\-]+(/[\w\-.]+)+$#', $tok)) {
                $found[] = [$where, $no, 'High-entropy string', mask($tok)];
            }
        }
    }
};

if ($git) {
    chdir($root);
    $log = shell_exec('git log --all -p --no-color --unified=0 --format="commit %h" 2>/dev/null') ?? '';
    if ($log === '') { fwrite(STDERR, "no git history found in $root\n"); exit(2); }
    $commit = '?'; $file = '?';
    foreach (explode("\n", $log) as $l) {
        if (str_starts_with($l, 'commit ')) { $commit = substr($l, 7); }
        elseif (str_starts_with($l, '+++ b/')) { $file = substr($l, 6); }
        elseif ($l !== '' && $l[0] === '+' && !str_starts_with($l, '+++')) { $scanLine("commit $commit:$file", 0, substr($l, 1)); }
    }
} else {
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn($f) => !($f->isDir() && in_array($f->getFilename(), $skipDirs, true))));
    foreach ($it as $f) {
        if (!$f->isFile() || in_array(strtolower($f->getExtension()), $skipExt, true) || in_array($f->getFilename(), $skipFiles, true) || $f->getSize() > 2_000_000) { continue; }
        $rel = ltrim(substr($f->getPathname(), strlen($root)), '/\\');
        foreach ($ignore as $ig) { if (str_starts_with($rel, $ig)) { continue 2; } }
        if ($f->getFilename() === '.env') { $found[] = [$rel, 0, 'A real .env file is in the tree (must be git-ignored, never packaged)', '-']; continue; }
        foreach (file($f->getPathname()) ?: [] as $i => $line) { $scanLine($rel, $i + 1, rtrim($line)); }
    }
}
if (!$found) { echo "secret_scan: scanned " . ($git ? 'git history' : 'working tree') . " of $root: 0 findings\nRESULT: CLEAN\n"; exit(0); }
foreach ($found as [$w, $n, $name, $val]) { printf("FINDING  %-34s %s%s  %s\n", $name, $w, $n ? ":$n" : '', $val); }
echo "secret_scan: " . count($found) . " finding(s)\nRESULT: SECRETS FOUND\n";
exit(1);
