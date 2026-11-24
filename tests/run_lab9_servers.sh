#!/usr/bin/env bash
# LAB 9: starts BEFORE (port 8130, plain server) and AFTER (port 8131, optimised + static header mirror). Sandbox helper.
# usage: run_lab9_servers.sh <before_dir> <after_dir>
set -u
export ZAMOVE_ENV=test ZAMOVE_DB_DRIVER=sqlite ZAMOVE_APP_KEY="$(printf 'k%.0s' {1..40})" PHP_CLI_SERVER_WORKERS=4
DB=/tmp/zamove_lab9.db; rm -f $DB
(cd "$2" && php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec(file_get_contents("tests/schema_sqlite_lab6.sql"));' $DB)
export ZAMOVE_SQLITE=$DB
(cd "$1" && php -S 127.0.0.1:8130 -t . tests/router.php >/tmp/lab9_before.log 2>&1 &)
(cd "$2" && ZAMOVE_STATIC_MIRROR=1 php -S 127.0.0.1:8131 -t . tests/router.php >/tmp/lab9_after.log 2>&1 &)
sleep 1; echo started
