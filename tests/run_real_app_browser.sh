#!/usr/bin/env bash
# REAL APP (NEW FILE): sandbox run of the browser story (php -S + SQLite from the migrations).  bash tests/run_real_app_browser.sh [shots-dir]
cd "$(dirname "$0")/.."
DB=/tmp/zm_realb_$$.db; PORT="${PORT:-8192}"; SH="${1:-/tmp/zm_shots}"; mkdir -p "$SH"
export ZAMOVE_DIST="${ZAMOVE_DIST:-1}" ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE="$DB" ZAMOVE_ENV=test ZAMOVE_RL_OFF=1 ZAMOVE_ALLOW_HTTP=1 ZAMOVE_APP_KEY="$(printf 'k%.0s' {1..40})"
rm -f "$DB"; php tools/migrate.php up >/dev/null
php -r 'require "api/lib/db.php"; db()->prepare("INSERT INTO users (full_name,email,phone,password_hash,role) VALUES (?,?,?,?,?)")->execute(["Admin","admin@example.com","+260971000009",password_hash("Adm1nPassw0rd",PASSWORD_DEFAULT),"admin"]);'
php -S "localhost:$PORT" -t . tests/router.php >/tmp/zm_realb_php.log 2>&1 & SRV=$!; trap 'kill $SRV 2>/dev/null; rm -f "$DB"' EXIT; sleep 1
node tests/real_app_browser_tests.js "http://localhost:$PORT" "$SH"
