<?php
declare(strict_types=1);
/**
 * LAB 7 (NEW FILE) - Zamove migration runner (our counterpart of `knex migrate:*` / `alembic upgrade`).
 *
 *   php tools/migrate.php status                 which migrations are applied (batch, checksum)
 *   php tools/migrate.php up [--step=N]          apply all pending migrations as ONE batch (or only the next N)
 *   php tools/migrate.php down [--step=N]        roll back the last batch (or the last N migrations)
 *   php tools/migrate.php reset                  roll back everything
 *   php tools/migrate.php fresh                  drop every table, then run all migrations
 *   php tools/migrate.php make add_something     create migrations/NNN_add_something.php from a template
 *   php tools/migrate.php sql [up|down]          print the SQL that would run for the connected database, without running it
 *
 * The database is chosen exactly like the API: XAMPP MySQL by default (settings in api/config.php),
 * or SQLite when ZAMOVE_DB_DRIVER=sqlite (automated tests). Applied migrations are recorded in the
 * `schema_migrations` table together with a SHA-256 checksum of the file, so an edited migration is noticed.
 */

require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/schema_builder.php';

$dir = __DIR__ . '/../migrations';
$cmd = $argv[1] ?? 'status';
$step = null;
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--step=(\d+)$/', $a, $m)) $step = (int) $m[1];
}

$pdo = db();
$isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
if (!$isMysql) $pdo->exec('PRAGMA foreign_keys = ON');            // SQLite enforces foreign keys only when asked
$schema = new Schema($pdo);

function ensure_table(PDO $pdo, bool $isMysql): void
{
    $pdo->exec($isMysql
        ? 'CREATE TABLE IF NOT EXISTS schema_migrations (
             id INT NOT NULL AUTO_INCREMENT, migration VARCHAR(190) NOT NULL, batch INT NOT NULL,
             checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id), UNIQUE KEY uq_schema_migrations_name (migration)) ENGINE=InnoDB'
        : 'CREATE TABLE IF NOT EXISTS schema_migrations (
             id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(190) NOT NULL UNIQUE, batch INTEGER NOT NULL,
             checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
}

function migration_files(string $dir): array
{
    $files = glob($dir . '/[0-9][0-9][0-9]_*.php') ?: [];
    sort($files);
    $out = [];
    foreach ($files as $f) $out[basename($f, '.php')] = $f;
    return $out;
}

function applied(PDO $pdo): array
{
    return $pdo->query('SELECT migration, batch, checksum FROM schema_migrations ORDER BY id')->fetchAll();
}

/** Run one migration's up/down. SQLite can roll DDL back, so it runs inside a transaction; MySQL commits DDL implicitly. */
function run_one(PDO $pdo, Schema $schema, bool $isMysql, string $name, string $file, string $direction, int $batch): void
{
    $m = require $file;
    if (!isset($m[$direction]) || !is_callable($m[$direction])) throw new RuntimeException("$name has no '$direction'");
    $useTx = !$isMysql;
    if ($useTx) $pdo->beginTransaction();
    try {
        $m[$direction]($schema);
        if ($direction === 'up') {
            $pdo->prepare('INSERT INTO schema_migrations (migration, batch, checksum) VALUES (?, ?, ?)')
                ->execute([$name, $batch, hash_file('sha256', $file)]);
        } else {
            $pdo->prepare('DELETE FROM schema_migrations WHERE migration = ?')->execute([$name]);
        }
        if ($useTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($useTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function drop_everything(PDO $pdo, bool $isMysql): void
{
    if ($isMysql) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } else {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TABLE IF EXISTS \"$t\"");
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}

function do_up(PDO $pdo, Schema $schema, bool $isMysql, string $dir, ?int $step): int
{
    ensure_table($pdo, $isMysql);
    $done = array_column(applied($pdo), 'migration');
    $pending = array_diff_key(migration_files($dir), array_flip($done));
    if (!$pending) { echo "Nothing to migrate. Database is up to date.\n"; return 0; }
    $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch), 0) FROM schema_migrations')->fetchColumn() + 1;
    $n = 0;
    foreach ($pending as $name => $file) {
        if ($step !== null && $n >= $step) break;
        run_one($pdo, $schema, $isMysql, $name, $file, 'up', $batch);
        echo "Migrated   [batch $batch]  $name\n"; $n++;
    }
    return $n;
}

function do_down(PDO $pdo, Schema $schema, bool $isMysql, string $dir, ?int $step, bool $all = false): int
{
    ensure_table($pdo, $isMysql);
    $files = migration_files($dir);
    $rows = array_reverse(applied($pdo));
    if (!$rows) { echo "Nothing to roll back.\n"; return 0; }
    $lastBatch = (int) $rows[0]['batch'];
    $n = 0;
    foreach ($rows as $r) {
        if (!$all && $step === null && (int) $r['batch'] !== $lastBatch) break;
        if (!$all && $step !== null && $n >= $step) break;
        if (!isset($files[$r['migration']])) throw new RuntimeException('Migration file missing: ' . $r['migration']);
        run_one($pdo, $schema, $isMysql, $r['migration'], $files[$r['migration']], 'down', 0);
        echo "Rolled back [batch {$r['batch']}]  {$r['migration']}\n"; $n++;
    }
    return $n;
}

try {
    switch ($cmd) {
        case 'status':
            ensure_table($pdo, $isMysql);
            $rows = applied($pdo); $byName = array_column($rows, null, 'migration');
            printf("%-48s %-9s %s\n", 'Migration', 'Status', 'Batch');
            foreach (migration_files($dir) as $name => $file) {
                $r = $byName[$name] ?? null;
                $state = $r ? ($r['checksum'] === hash_file('sha256', $file) ? 'Applied' : 'CHANGED') : 'Pending';
                printf("%-48s %-9s %s\n", $name, $state, $r ? $r['batch'] : '-');
            }
            break;
        case 'up':    do_up($pdo, $schema, $isMysql, $dir, $step); break;
        case 'down':  do_down($pdo, $schema, $isMysql, $dir, $step); break;
        case 'reset': do_down($pdo, $schema, $isMysql, $dir, null, true); break;
        case 'fresh':
            drop_everything($pdo, $isMysql); echo "Dropped all tables.\n";
            do_up($pdo, $schema, $isMysql, $dir, null); break;
        case 'sql':
            $dirn = $argv[2] ?? 'up';
            foreach (['mysql', 'sqlite'] as $drv) {
                if (($argv[3] ?? $drv) !== $drv) continue;
                $p = new Schema($pdo, $drv); $p->pretend = true;
                echo "-- ===== $drv ($dirn) =====\n";
                $list = migration_files($dir); if ($dirn === 'down') $list = array_reverse($list, true);
                foreach ($list as $name => $file) {
                    $m = require $file; $p->log = []; $m[$dirn]($p);
                    echo "-- $name\n" . implode(";\n", $p->log) . ";\n\n";
                }
            }
            break;
        case 'make':
            $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($argv[2] ?? ''));
            if ($slug === '' ) { fwrite(STDERR, "usage: php tools/migrate.php make add_something\n"); exit(1); }
            $next = str_pad((string) (count(migration_files($dir)) + 1), 3, '0', STR_PAD_LEFT);
            $path = "$dir/{$next}_$slug.php";
            file_put_contents($path, "<?php\ndeclare(strict_types=1);\n\nreturn [\n    'up' => function (Schema \$s): void {\n        // \$s->createTable(...), \$s->addColumn(...), \$s->createIndex(...)\n    },\n    'down' => function (Schema \$s): void {\n        // the exact reverse of 'up'\n    },\n];\n");
            echo "Created $path\n"; break;
        default:
            fwrite(STDERR, "unknown command '$cmd' (status, up, down, reset, fresh, make, sql)\n"); exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'MIGRATION FAILED: ' . $e->getMessage() . "\n"); exit(1);
}
