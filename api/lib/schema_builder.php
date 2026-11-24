<?php
declare(strict_types=1);

/**
 * LAB 7 (NEW FILE) - a small, Knex-style schema builder for PHP.
 *
 * The lab suggests Knex.js / Prisma / TypeORM / Alembic. Zamove runs on PHP + MySQL (XAMPP), so
 * this file plays the part of Knex's `schema` object: a migration describes tables in PHP and the
 * builder writes the matching SQL for the connected database:
 *
 *      MySQL / MariaDB  -> InnoDB, utf8mb4, ENUM, named CONSTRAINTs      (XAMPP, production)
 *      SQLite           -> same logical schema, used by the automated tests in this sandbox
 *
 *   $s->createTable('routes', function (Blueprint $t) {
 *       $t->id('route_id');
 *       $t->integer('operator_id');
 *       $t->string('origin', 120);
 *       $t->decimal('base_fare', 10, 2);
 *       $t->foreign('operator_id')->references('operators', 'operator_id');
 *   });
 */

final class Column
{
    public string $name; public string $type; public bool $null = false; public $default = null;
    public bool $hasDefault = false; public bool $unique = false; public bool $pk = false; public bool $auto = false;
    public ?string $check = null; public bool $defaultRaw = false;
    public function __construct(string $name, string $type) { $this->name = $name; $this->type = $type; }
    public function nullable(): self { $this->null = true; return $this; }
    public function default($v): self { $this->default = $v; $this->hasDefault = true; return $this; }
    public function defaultNow(): self { $this->default = 'CURRENT_TIMESTAMP'; $this->hasDefault = true; $this->defaultRaw = true; return $this; }
    public function unique(): self { $this->unique = true; return $this; }
    public function check(string $expr): self { $this->check = $expr; return $this; }
}

final class ForeignKey
{
    public string $col; public string $refTable = ''; public string $refCol = ''; public string $onDelete = 'RESTRICT'; public string $name = '';
    public function __construct(string $col) { $this->col = $col; }
    public function references(string $table, string $col): self { $this->refTable = $table; $this->refCol = $col; return $this; }
    public function onDelete(string $rule): self { $this->onDelete = strtoupper($rule); return $this; }
}

final class Blueprint
{
    public string $table; public array $columns = []; public array $fks = []; public array $uniques = [];
    public array $checks = []; public array $indexes = []; public ?array $compositePk = null;
    public function __construct(string $table) { $this->table = $table; }
    private function add(Column $c): Column { $this->columns[] = $c; return $c; }

    public function id(string $name): Column { $c = $this->add(new Column($name, 'id')); $c->pk = true; $c->auto = true; return $c; }
    public function integer(string $name): Column { return $this->add(new Column($name, 'integer')); }
    public function string(string $name, int $len = 255): Column { return $this->add(new Column($name, "string:$len")); }
    public function char(string $name, int $len): Column { return $this->add(new Column($name, "char:$len")); }
    public function text(string $name): Column { return $this->add(new Column($name, 'text')); }
    public function decimal(string $name, int $p = 10, int $s = 2): Column { return $this->add(new Column($name, "decimal:$p,$s")); }
    public function enum(string $name, array $values): Column { return $this->add(new Column($name, 'enum:' . implode('|', $values))); }
    public function datetime(string $name): Column { return $this->add(new Column($name, 'datetime')); }
    public function foreign(string $col): ForeignKey { $f = new ForeignKey($col); $this->fks[] = $f; return $f; }
    public function unique(array $cols, string $name): void { $this->uniques[] = [$name, $cols]; }
    public function index(array $cols, string $name): void { $this->indexes[] = [$name, $cols]; }
    public function check(string $name, string $expr): void { $this->checks[] = [$name, $expr]; }
    public function primary(array $cols): void { $this->compositePk = $cols; }
}

final class Schema
{
    public PDO $pdo; public string $driver; public array $log = []; public bool $pretend = false;   // pretend = only record the SQL
    public function __construct(PDO $pdo, ?string $driver = null) { $this->pdo = $pdo; $this->driver = $driver ?? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME); }
    public function isMysql(): bool { return $this->driver === 'mysql'; }

    private function q(string $id): string { return $this->isMysql() ? "`$id`" : "\"$id\""; }
    private function run(string $sql): void { $this->log[] = $sql; if (!$this->pretend) { $this->pdo->exec($sql); } }
    /** Execute raw SQL (used for data back-fills). */
    public function statement(string $sql): void { $this->run($sql); }

    private function lit($v): string
    {
        if (is_int($v) || is_float($v)) return (string) $v;
        return $this->pdo->quote((string) $v);
    }

    private function colSql(Column $c, bool $inlinePk = true): string
    {
        [$kind, $arg] = array_pad(explode(':', $c->type, 2), 2, '');
        $name = $this->q($c->name);
        if ($kind === 'id') {
            return $this->isMysql() ? "$name INT NOT NULL AUTO_INCREMENT" : "$name INTEGER PRIMARY KEY AUTOINCREMENT";
        }
        $type = match ($kind) {
            'integer'  => $this->isMysql() ? 'INT' : 'INTEGER',
            'string'   => "VARCHAR($arg)",
            'char'     => "CHAR($arg)",
            'text'     => 'TEXT',
            'decimal'  => "DECIMAL($arg)",
            'datetime' => 'DATETIME',
            'enum'     => $this->isMysql()
                ? 'ENUM(' . implode(',', array_map(fn($v) => $this->lit($v), explode('|', $arg))) . ')'
                : 'TEXT',
        };
        $sql = "$name $type" . ($c->null ? ' NULL' : ' NOT NULL');
        if ($c->hasDefault) $sql .= ' DEFAULT ' . ($c->defaultRaw ? $c->default : $this->lit($c->default));
        if ($kind === 'enum' && !$this->isMysql()) {
            $sql .= " CHECK ($name IN (" . implode(',', array_map(fn($v) => $this->lit($v), explode('|', $arg))) . '))';
        }
        if ($c->check !== null) $sql .= ' CHECK (' . $c->check . ')';
        return $sql;
    }

    public function createTable(string $table, callable $define): void
    {
        $t = new Blueprint($table); $define($t);
        $parts = []; $post = [];
        foreach ($t->columns as $c) {
            $parts[] = $this->colSql($c);
            if ($c->unique) $t->uniques[] = ['uq_' . $table . '_' . $c->name, [$c->name]];
        }
        if ($this->isMysql()) {
            foreach ($t->columns as $c) if ($c->pk) $parts[] = 'PRIMARY KEY (' . $this->q($c->name) . ')';
        }
        if ($t->compositePk) $parts[] = 'PRIMARY KEY (' . implode(', ', array_map([$this, 'q'], $t->compositePk)) . ')';
        foreach ($t->uniques as [$n, $cols]) {
            $parts[] = 'CONSTRAINT ' . $this->q($n) . ' UNIQUE (' . implode(', ', array_map([$this, 'q'], $cols)) . ')';
        }
        foreach ($t->fks as $f) {
            $f->name = $f->name ?: 'fk_' . $table . '_' . $f->col;
            $parts[] = 'CONSTRAINT ' . $this->q($f->name) . ' FOREIGN KEY (' . $this->q($f->col) . ') REFERENCES '
                     . $this->q($f->refTable) . ' (' . $this->q($f->refCol) . ') ON DELETE ' . $f->onDelete;
        }
        foreach ($t->checks as [$n, $expr]) $parts[] = 'CONSTRAINT ' . $this->q($n) . ' CHECK (' . $expr . ')';
        $sql = 'CREATE TABLE ' . $this->q($table) . " (\n  " . implode(",\n  ", $parts) . "\n)"
             . ($this->isMysql() ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '');
        $this->run($sql);
        foreach ($t->indexes as [$n, $cols]) $this->createIndex($table, $n, $cols);
    }

    public function dropTable(string $table): void { $this->run('DROP TABLE IF EXISTS ' . $this->q($table)); }

    /** ALTER TABLE ... ADD COLUMN, e.g.  $s->addColumn('schedules', fn($t) => $t->integer('seats_available')->default(0)); */
    public function addColumn(string $table, callable $define): void
    {
        $t = new Blueprint($table); $define($t);
        foreach ($t->columns as $c) $this->run('ALTER TABLE ' . $this->q($table) . ' ADD COLUMN ' . $this->colSql($c));
    }

    public function dropColumn(string $table, string $column): void
    {
        $this->run('ALTER TABLE ' . $this->q($table) . ' DROP COLUMN ' . $this->q($column));
    }

    public function createIndex(string $table, string $name, array $cols, bool $unique = false): void
    {
        $this->run('CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->q($name) . ' ON ' . $this->q($table)
                 . ' (' . implode(', ', array_map([$this, 'q'], $cols)) . ')');
    }

    public function dropIndex(string $table, string $name): void
    {
        $this->run($this->isMysql() ? 'DROP INDEX ' . $this->q($name) . ' ON ' . $this->q($table) : 'DROP INDEX IF EXISTS ' . $this->q($name));
    }

    public function hasTable(string $table): bool
    {
        if ($this->isMysql()) {
            $s = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        } else {
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        }
        $s->execute([$table]);
        return (int) $s->fetchColumn() > 0;
    }
}
