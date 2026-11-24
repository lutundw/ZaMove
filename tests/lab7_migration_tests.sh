#!/usr/bin/env bash
# LAB 7 Task 1: migrations can be initialised, migrated forward and rolled back. Works on a throw-away SQLite DB
# (or on MySQL: run with ZAMOVE_DB_DRIVER unset and your XAMPP settings, on an EMPTY database).
cd "$(dirname "$0")/.."
DB="${ZAMOVE_SQLITE:-$(mktemp -u /tmp/zm_mig_XXXXXX.db)}"; export ZAMOVE_DB_DRIVER="${ZAMOVE_DB_DRIVER:-sqlite}" ZAMOVE_SQLITE="$DB"
pass=0; fail=0
ok(){ if eval "$2"; then echo "RESULT: PASS ($1)"; pass=$((pass+1)); else echo "RESULT: FAIL ($1)"; fail=$((fail+1)); fi; }
m(){ echo "\$ php tools/migrate.php $*"; php tools/migrate.php "$@"; }
tables(){ php tests/db_query.php "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'" 2>/dev/null | grep -c '"name"' ; }
rm -f "$DB"
echo "### M1 status on an empty database";            m status
echo "### M2 migrate forward (all 5)";                 m up | tee /tmp/zm_up.txt
ok "M2 five migrations applied in batch 1" "[ \$(grep -c 'Migrated' /tmp/zm_up.txt) = 5 ]"
echo "### M3 status after up";                         m status | tee /tmp/zm_st.txt
ok "M3 status shows 004_add_performance_indexes applied" "grep -q 004_add_performance_indexes /tmp/zm_st.txt && ! grep -qi pending /tmp/zm_st.txt"
echo "### M4 running up again changes nothing";        m up | tee /tmp/zm_up2.txt
ok "M4 up is idempotent (nothing to migrate)" "! grep -q Migrated /tmp/zm_up2.txt"
echo "### M5 roll back the last 3 migrations (005, 004, 003)";         m down --step=3 | tee /tmp/zm_d2.txt
ok "M5 three migrations rolled back" "[ \$(grep -c 'Rolled back' /tmp/zm_d2.txt) = 3 ]"
ok "M5 seats_available column removed by 003 down" "! php tests/db_query.php \"SELECT seats_available FROM schedules LIMIT 1\" >/dev/null 2>&1"
echo "### M6 migrate forward again";                   m up | tee /tmp/zm_up3.txt
ok "M6 re-applied cleanly" "[ \$(grep -c 'Migrated' /tmp/zm_up3.txt) = 3 ]"
echo "### M7 reset: roll back everything";             m reset | tail -5
ok "M7 only schema_migrations remains" "[ \$(tables) -le 1 ]"
echo "### M8 fresh: drop all and migrate";             m fresh | tail -5
ok "M8 schema rebuilt (core tables present)" "php tests/db_query.php \"SELECT COUNT(*) FROM schedules\" >/dev/null 2>&1"
echo "### M9 edited migration is detected (checksum)"
cp migrations/004_add_performance_indexes.php /tmp/zm_004.bak; echo "// edited" >> migrations/004_add_performance_indexes.php
m status 2>&1 | tee /tmp/zm_ck.txt | tail -4; cp /tmp/zm_004.bak migrations/004_add_performance_indexes.php
ok "M9 checksum change is reported" "grep -qi 'changed\|checksum\|modified' /tmp/zm_ck.txt"
echo "SUMMARY: $pass passed, $fail failed"; rm -f "$DB"; [ $fail = 0 ]
