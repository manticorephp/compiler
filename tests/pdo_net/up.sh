#!/bin/bash
# Start the pdo_net test servers (idempotent): mc-pg :54320, mc-pg-ssl :54323 and mc-my-ssl :33064 (TLS, certs in tests/pdo_net/.certs), mc-my :33060, mc-my-native :33062 (users nu/npw, ne/''), user u / pw pw / db d.
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
# TLS postgres: certs generated on the host into .certs (gitignored, removed by down.sh), copied into the container at create time.
# hba: `u` and `cu` only over TLS (cu authenticates by client certificate), plaintext from outside is rejected.
CERTS="$(cd "$(dirname "$0")" && pwd)/.certs"
bash "$(dirname "$0")/mkcerts.sh" "$CERTS"
if ! docker inspect mc-pg-ssl >/dev/null 2>&1; then
    docker create --name mc-pg-ssl -p 127.0.0.1:54323:5432 -e POSTGRES_USER=u -e POSTGRES_PASSWORD=pw -e POSTGRES_DB=d --entrypoint sh postgres:16-alpine -c '
        mkdir -p /tls && cp /certsrc/ca.pem /certsrc/server.crt /certsrc/server.key /tls/ && chown -R postgres:postgres /tls && chmod 600 /tls/server.key
        printf "local all all trust\nhostssl all u all scram-sha-256\nhostssl d cu all cert\nhostnossl all all all reject\nhost all all all reject\n" > /tls/pg_hba.conf
        chown postgres:postgres /tls/pg_hba.conf
        exec docker-entrypoint.sh postgres -c ssl=on -c ssl_cert_file=/tls/server.crt -c ssl_key_file=/tls/server.key -c ssl_ca_file=/tls/ca.pem -c hba_file=/tls/pg_hba.conf' >/dev/null
    docker cp "$CERTS" mc-pg-ssl:/certsrc
fi
start mc-pg-ssl
start mc-my -p 127.0.0.1:33060:3306 -e MYSQL_USER=u -e MYSQL_PASSWORD=pw -e MYSQL_DATABASE=d -e MYSQL_ROOT_PASSWORD=pw mysql:8.4
# TLS mysql with the same throwaway CA: a server certificate that names localhost/127.0.0.1 (mc-my's own cannot pass verification),
# and a user `cu` that must present a client certificate. mc-my keeps its auto-generated certificate for the verify-off cases.
if ! docker inspect mc-my-ssl >/dev/null 2>&1; then
    docker create --name mc-my-ssl -p 127.0.0.1:33064:3306 -e MYSQL_USER=u -e MYSQL_PASSWORD=pw -e MYSQL_DATABASE=d -e MYSQL_ROOT_PASSWORD=pw --entrypoint sh mysql:8.4 -c '
        mkdir -p /tls && cp /certsrc/ca.pem /certsrc/server.crt /certsrc/server.key /tls/ && chown -R mysql:mysql /tls && chmod 600 /tls/server.key
        exec docker-entrypoint.sh mysqld --ssl-ca=/tls/ca.pem --ssl-cert=/tls/server.crt --ssl-key=/tls/server.key' >/dev/null
    docker cp "$CERTS" mc-my-ssl:/certsrc
fi
start mc-my-ssl
# mysql 8.4 ships mysql_native_password OFF: a second server with it on, for the native-password and auth-switch cases
start mc-my-native -p 127.0.0.1:33062:3306 -e MYSQL_DATABASE=d -e MYSQL_ROOT_PASSWORD=pw mysql:8.4 --mysql-native-password=ON
# TCP logins: the init-time temporary servers listen on the unix socket only
wait_for mc-pg 60 docker exec -e PGPASSWORD=pw mc-pg psql -h 127.0.0.1 -U u d -c 'select 1'
wait_for mc-pg-ssl 60 docker exec mc-pg-ssl psql -U u d -c "select 1"
docker exec mc-pg-ssl psql -U u d -c "create user cu" 2>/dev/null || true
wait_for mc-my 120 docker exec mc-my mysql -h 127.0.0.1 -uu -ppw d -e 'select 1'
wait_for mc-my-native 120 docker exec mc-my-native mysql -h 127.0.0.1 -uroot -ppw d -e 'select 1'
wait_for mc-my-ssl 120 docker exec mc-my-ssl mysql -h 127.0.0.1 -uu -ppw d -e 'select 1'
docker exec mc-my-ssl mysql -h 127.0.0.1 -uroot -ppw -e "create user if not exists 'cu'@'%' identified by 'pw' require x509; grant all on d.* to 'cu'@'%'" 2>/dev/null
docker exec mc-my-native mysql -h 127.0.0.1 -uroot -ppw -e "create user if not exists 'nu'@'%' identified with mysql_native_password by 'npw'; create user if not exists 'ne'@'%' identified with mysql_native_password by ''; grant all on d.* to 'nu'@'%'; grant all on d.* to 'ne'@'%'"
echo up
