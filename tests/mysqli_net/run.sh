#!/bin/bash
# mysqli_net: byte parity of compiled mysqli programs against php, on live servers (tests/pdo_net/up.sh starts them).
#   tests/mysqli_net/run.sh [-k substr] [--server mysql|mariadb] [--shard i/n]
# --server picks the server every case talks to (mysql = mc-my :33060, default; mariadb = mc-maria :33061); the oracle is
# live php on THAT server. A case header line `// servers: mysql` (default: both) limits a case; the other server SKIPs it.
# A header `// requires-env: VAR` SKIPs the case unless VAR is set (socket.php needs MYSQLI_SOCKET).
# Cases read MYSQLI_HOST / MYSQLI_PORT / MYSQLI_USER / MYSQLI_PASS / MYSQLI_DB. SKIP when the server is unreachable.
# The exit status is part of the behaviour: php's and the binary's must agree.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
FILTER=""
SERVER=mysql
SHARD_I=0; SHARD_N=1
while [ $# -gt 0 ]; do
    case "$1" in
        -k) FILTER="${2:-}"; shift 2 ;;
        --server) SERVER="${2:-}"; shift 2 ;;
        --shard)
            [[ "${2:-}" =~ ^([0-9]+)/([0-9]+)$ ]] && [ "${BASH_REMATCH[1]}" -ge 1 ] && [ "${BASH_REMATCH[1]}" -le "${BASH_REMATCH[2]}" ] || { echo "--shard takes i/n with 1 <= i <= n" >&2; exit 2; }
            SHARD_I=$((BASH_REMATCH[1] - 1)); SHARD_N=${BASH_REMATCH[2]}; shift 2 ;;
        *) echo "usage: run.sh [-k substr] [--server mysql|mariadb] [--shard i/n]" >&2; exit 2 ;;
    esac
done
case "$SERVER" in mysql) PORT=33060 ;; mariadb) PORT=33061 ;; *) echo "unknown --server $SERVER (mysql|mariadb)" >&2; exit 2 ;; esac
export MYSQLI_HOST="${MYSQLI_HOST-127.0.0.1}" MYSQLI_PORT="${MYSQLI_PORT-$PORT}" MYSQLI_USER="${MYSQLI_USER-u}" MYSQLI_PASS="${MYSQLI_PASS-pw}" MYSQLI_DB="${MYSQLI_DB-d}"
WORK="$ROOT/tests/mysqli_net/.work"
mkdir -p "$WORK"
trap 'rm -rf "$WORK"' EXIT
trap 'exit 130' INT TERM

reachable() { (exec 3<>"/dev/tcp/$MYSQLI_HOST/$MYSQLI_PORT") 2>/dev/null; }

pass=0; fail=0; skip=0; unreach=0
k=0
for f in tests/mysqli_net/cases/*.php; do
    n=$(basename "$f" .php)
    [[ -n "$FILTER" && "$n" != *"$FILTER"* ]] && continue
    k=$((k+1)); [ $(( (k-1) % SHARD_N )) -eq "$SHARD_I" ] || continue
    only=$(head -n 6 "$f" | sed -n 's|^// servers: *||p' | tr -d ' ')
    if [ -n "$only" ] && [[ ",$only," != *",$SERVER,"* ]]; then echo "SKIP $n (servers: $only, running $SERVER)"; skip=$((skip+1)); continue; fi
    need=$(head -n 6 "$f" | sed -n 's|^// requires-env: *||p' | tr -d ' ')
    if [ -n "$need" ] && [ -z "${!need:-}" ]; then echo "SKIP $n (requires-env: $need is not set)"; skip=$((skip+1)); continue; fi
    if ! reachable; then echo "SKIP $n (server $MYSQLI_HOST:$MYSQLI_PORT unreachable)"; skip=$((skip+1)); unreach=$((unreach+1)); continue; fi
    exp=$(php -d xdebug.mode=off "$f" 2>&1 | head -c 1000000); prc=${PIPESTATUS[0]}
    if ! bin/manticore compile "$f" -o "$WORK/$n" >"$WORK/$n.log" 2>&1; then
        echo "FAIL $n (compile)"; tail -n 20 "$WORK/$n.log" | cut -c1-300; fail=$((fail+1)); continue
    fi
    act=$("$WORK/$n" 2>&1 | head -c 1000000); nrc=${PIPESTATUS[0]}
    rm -f "$WORK/$n" "$WORK/$n.log"
    if [ "$nrc" != "$prc" ]; then echo "FAIL $n (native exit status $nrc, php $prc)"; fail=$((fail+1)); continue; fi
    # a case that printed its own assertion failure fails even when php printed the same
    if grep -q 'ASSERTION FAILED' <<<"$exp$act"; then echo "FAIL $n (the case reports a failed assertion)"; fail=$((fail+1)); continue; fi
    if [ "$exp" = "$act" ]; then echo "PASS $n"; pass=$((pass+1))
    else echo "FAIL $n"; echo "--- diff (php < > native)"; diff <(echo "$exp") <(echo "$act") | head -n 40 | cut -c1-300; fail=$((fail+1)); fi
done
echo "pass=$pass fail=$fail skip=$skip"
# a run where every case SKIPped proved nothing: it must not look green
if [ "$pass" = 0 ] && [ "$fail" = 0 ] && [ "$skip" != 0 ]; then echo "FAIL: nothing ran against a server ($skip skipped; is the server up? tests/pdo_net/up.sh)" >&2; exit 1; fi
if [ "${MC_PDO_STRICT:-0}" = 1 ] && [ "$unreach" != 0 ]; then echo "FAIL: $unreach case(s) skipped because the server was unreachable (MC_PDO_STRICT=1)" >&2; exit 1; fi
[ "$fail" = 0 ]
