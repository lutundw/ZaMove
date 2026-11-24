#!/usr/bin/env bash
# LAB 7, Task 4 on XAMPP: prove connection reuse and zero leak with MySQL's own counters.
# Run from Git Bash or WSL with Apache Bench installed (C:\xampp\apache\bin\ab.exe):
#   bash tests/pool_check_mysql.sh http://localhost/zamove/api/v1/schedules?origin=Lusaka\&destination=Kitwe
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql} -u root -N -B"; URL="${1:?give the schedules URL}"
st() { $MYSQL -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Max_used_connections','Aborted_connects','Connections')" | tr '\t\n' '= '; echo; }
echo "BEFORE: $(st)"; ab -n 5000 -c 50 "$URL" | grep -E "Requests per second|Failed requests|Time per request|Non-2xx"
echo "AFTER : $(st)"; sleep 70; echo "IDLE  : $(st)   (Threads_connected falls back after wait_timeout)"
echo "Expect: Connections grows by about the number of workers (reuse), NOT by 5000; Threads_connected <= ThreadsPerChild; Aborted_connects stays 0."
