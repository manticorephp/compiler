#!/bin/bash
# Start the pdo_net test servers (idempotent): mc-pg :54320, mc-my :33060, user u / pw pw / db d.
set -eu
start() { # name, run-args...
    local n="$1"; shift
    if [ "$(docker inspect -f '{{.State.Running}}' "$n" 2>/dev/null)" = true ]; then return; fi
    if docker inspect "$n" >/dev/null 2>&1; then docker start "$n" >/dev/null; else docker run -d --name "$n" "$@" >/dev/null; fi
}
start mc-pg -p 54320:5432 -e POSTGRES_USER=u -e POSTGRES_PASSWORD=pw -e POSTGRES_DB=d postgres:16-alpine
start mc-my -p 33060:3306 -e MYSQL_USER=u -e MYSQL_PASSWORD=pw -e MYSQL_DATABASE=d -e MYSQL_ROOT_PASSWORD=pw mysql:8.4
for i in $(seq 1 60); do docker exec mc-pg pg_isready -U u -d d >/dev/null 2>&1 && break; sleep 1; done
for i in $(seq 1 90); do docker exec mc-my mysqladmin ping -h 127.0.0.1 -upw -ppw >/dev/null 2>&1 && break; sleep 1; done
# the mysql image restarts once during init; require two consecutive successful logins
for i in $(seq 1 60); do docker exec mc-my mysql -h 127.0.0.1 -uu -ppw d -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
echo up
