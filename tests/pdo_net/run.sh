#!/bin/bash
# pdo_net: byte parity of compiled PDO network drivers against php, on live servers.
#   tests/pdo_net/run.sh [-k substr]
# Cases named pgsql_* / mysql_* use PDO_PG_DSN / PDO_MY_DSN; SKIP when the server is unreachable.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
FILTER=""
[ "${1:-}" = "-k" ] && FILTER="$2"
export PDO_PG_DSN="${PDO_PG_DSN-pgsql:host=127.0.0.1;port=54320;dbname=d}"
export PDO_MY_DSN="${PDO_MY_DSN-mysql:host=127.0.0.1;port=33060;dbname=d}"
WORK="$ROOT/tests/pdo_net/.work"
mkdir -p "$WORK"
trap 'rm -rf "$WORK"' EXIT

reachable() { # dsn
    local h p
    h=$(sed -n 's/.*host=\([^;]*\).*/\1/p' <<<"$1"); p=$(sed -n 's/.*port=\([^;]*\).*/\1/p' <<<"$1")
    [ -n "$h" ] && [ -n "$p" ] && (exec 3<>"/dev/tcp/$h/$p") 2>/dev/null
}

pass=0; fail=0; skip=0
for f in tests/pdo_net/cases/*.php; do
    n=$(basename "$f" .php)
    [[ -n "$FILTER" && "$n" != *"$FILTER"* ]] && continue
    case "$n" in pgsql_*) dsn="$PDO_PG_DSN" ;; mysql_*) dsn="$PDO_MY_DSN" ;; *) echo "bad case name $n: must start with pgsql_ or mysql_" >&2; exit 2 ;; esac
    if [ -z "$dsn" ] || ! reachable "$dsn"; then echo "SKIP $n"; skip=$((skip+1)); continue; fi
    exp=$(php -d xdebug.mode=off "$f" 2>&1)
    if ! bin/manticore compile "$f" -o "$WORK/$n" >"$WORK/$n.log" 2>&1; then
        echo "FAIL $n (compile)"; tail -n 20 "$WORK/$n.log"; fail=$((fail+1)); continue
    fi
    act=$("$WORK/$n" 2>&1)
    if [ "$exp" = "$act" ]; then echo "PASS $n"; pass=$((pass+1))
    else echo "FAIL $n"; echo "--- php"; echo "$exp" | head -n 20; echo "--- native"; echo "$act" | head -n 20; fail=$((fail+1)); fi
done
# Hostile-server cases: a fake server (Zend php) per mode, the compiled client, hand-written expectations.
if [[ -z "$FILTER" || "pgsql_hostile" == *"$FILTER"* ]]; then
    H=tests/pdo_net/hostile
    bin/manticore compile $H/client.php -o "$WORK/hostile" >"$WORK/hostile.log" 2>&1 || { echo "FAIL pgsql_hostile (compile)"; tail -n 20 "$WORK/hostile.log"; fail=$((fail+1)); }
    if [ -x "$WORK/hostile" ]; then
        act=""
        for m in huge short authok_after_sasl sasl_final_unsolicited truncated_datarow truncated_rowdesc copy_in; do
            rm -f "$WORK/port"
            php -d xdebug.mode=off $H/server.php $m "$WORK/port" >/dev/null 2>&1 &
            sp=$!
            for _ in $(seq 1 50); do [ -s "$WORK/port" ] && break; sleep 0.1; done
            act+=$("$WORK/hostile" $m "$(cat "$WORK/port")" 2>&1)$'\n'
            kill $sp 2>/dev/null; wait $sp 2>/dev/null
        done
        if [ "$act" = "$(cat $H/hostile.out)"$'\n' ]; then echo "PASS pgsql_hostile"; pass=$((pass+1))
        else echo "FAIL pgsql_hostile"; diff <(echo "$act") $H/hostile.out | head -n 20; fail=$((fail+1)); fi
    fi
fi
echo "pass=$pass fail=$fail skip=$skip"
[ "$fail" = 0 ]
