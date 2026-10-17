#!/bin/bash
# Start the pdo_net test servers (idempotent): mc-pg :54320, mc-my :33060, mc-my-native :33062 (users nu/npw, ne/''), user u / pw pw / db d.
set -eu
start() { # name, run-args...
    local n="$1"; shift
    if [ "$(docker inspect -f '{{.State.Running}}' "$n" 2>/dev/null)" = true ]; then return; fi
    if docker inspect "$n" >/dev/null 2>&1; then docker start "$n" >/dev/null; else docker run -d --name "$n" "$@" >/dev/null; fi
}
# wait_for name tries cmd... : cmd must succeed twice in a row (init restarts the server once)
wait_for() {
    local n="$1" tries="$2" ok=0 i; shift 2
    for i in $(seq 1 "$tries"); do
        if "$@" >/dev/null 2>&1; then ok=$((ok+1)); [ "$ok" = 2 ] && return 0; else ok=0; fi
        sleep 1
    done
    echo "$n not ready" >&2; exit 1
}
start mc-pg -p 127.0.0.1:54320:5432 -e POSTGRES_USER=u -e POSTGRES_PASSWORD=pw -e POSTGRES_DB=d postgres:16-alpine
start mc-my -p 127.0.0.1:33060:3306 -e MYSQL_USER=u -e MYSQL_PASSWORD=pw -e MYSQL_DATABASE=d -e MYSQL_ROOT_PASSWORD=pw mysql:8.4
# mysql 8.4 ships mysql_native_password OFF: a second server with it on, for the native-password and auth-switch cases
start mc-my-native -p 127.0.0.1:33062:3306 -e MYSQL_DATABASE=d -e MYSQL_ROOT_PASSWORD=pw mysql:8.4 --mysql-native-password=ON
# TCP logins: the init-time temporary servers listen on the unix socket only
wait_for mc-pg 60 docker exec -e PGPASSWORD=pw mc-pg psql -h 127.0.0.1 -U u d -c 'select 1'
wait_for mc-my 120 docker exec mc-my mysql -h 127.0.0.1 -uu -ppw d -e 'select 1'
wait_for mc-my-native 120 docker exec mc-my-native mysql -h 127.0.0.1 -uroot -ppw d -e 'select 1'
docker exec mc-my-native mysql -h 127.0.0.1 -uroot -ppw -e "create user if not exists 'nu'@'%' identified with mysql_native_password by 'npw'; create user if not exists 'ne'@'%' identified with mysql_native_password by ''; grant all on d.* to 'nu'@'%'; grant all on d.* to 'ne'@'%'"
echo up
