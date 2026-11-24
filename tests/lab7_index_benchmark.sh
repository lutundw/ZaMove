#!/usr/bin/env bash
# =============================================================================
# Lab 7, Task 3 - indexing benchmark (before and after migration 004).
#   Sandbox:  ZAMOVE_DB_DRIVER=sqlite ZAMOVE_SQLITE=/path/bench.db bash tests/lab7_index_benchmark.sh
#   XAMPP:    set ZAMOVE_DB_NAME=zamove_bench (an EMPTY database) and run the same script; use
#             tests/explain_mysql.sql in phpMyAdmin / mysql for EXPLAIN ANALYZE.
# Uses the real migration engine: tables -> seats column (001-003), seed, measure, then migration 004, measure again.
# =============================================================================
set -u
cd "$(dirname "$0")/.."
OUT=evidence/lab7; mkdir -p "$OUT"
RUNS="${RUNS:-30}"
if [ "${ZAMOVE_DB_DRIVER:-}" = "sqlite" ]; then rm -f "$ZAMOVE_SQLITE"; fi
php tools/migrate.php fresh >/dev/null && php tools/migrate.php down >/dev/null   # clean slate: everything migrated, then undone ...
php tools/migrate.php fresh >/dev/null
php tools/migrate.php down --step=1 > /dev/null                                    # ... and 004 (indexes) removed again
php tools/migrate.php status
echo "--- seeding ---"
php tools/seed_bench.php ${SEED_ARGS:-}
echo "--- BEFORE: no performance indexes ---"
php tools/bench_queries.php before_index "$RUNS" > /dev/null
echo "--- migration 004 ---"
php tools/migrate.php up
if [ "${ZAMOVE_DB_DRIVER:-}" = "sqlite" ]; then php tests/db_query.php "ANALYZE" >/dev/null; else php tests/db_query.php "ANALYZE TABLE routes, schedules, bookings" >/dev/null; fi
echo "--- AFTER: indexes created ---"
php tools/bench_queries.php after_index "$RUNS" > /dev/null
php tools/bench_summary.php | tee "$OUT/benchmark_summary.txt"
