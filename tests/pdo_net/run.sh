#!/bin/bash
# pdo_net: byte parity of compiled PDO network drivers against php, on live servers.
#   tests/pdo_net/run.sh [-k substr]
# Cases named pgsql_* / mysql_* use PDO_PG_DSN / PDO_MY_DSN (mysql_native* : PDO_MY_NATIVE_DSN); SKIP when the server is unreachable.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
FILTER=""
[ "${1:-}" = "-k" ] && FILTER="$2"
export PDO_PG_DSN="${PDO_PG_DSN-pgsql:host=127.0.0.1;port=54320;dbname=d}"
export PDO_MY_DSN="${PDO_MY_DSN-mysql:host=127.0.0.1;port=33060;dbname=d}"
export PDO_MY_NATIVE_DSN="${PDO_MY_NATIVE_DSN-mysql:host=127.0.0.1;port=33062;dbname=d}"
WORK="$ROOT/tests/pdo_net/.work"
mkdir -p "$WORK"
trap 'rm -rf "$WORK"' EXIT

reachable() { # dsn
    local h p
    h=$(sed -n 's/.*host=\([^;]*\).*/\1/p' <<<"$1"); p=$(sed -n 's/.*port=\([^;]*\).*/\1/p' <<<"$1")
    [ -n "$h" ] && [ -n "$p" ] && (exec 3<>"/dev/tcp/$h/$p") 2>/dev/null   # no second command: under bash 3.2 a failed redirect does not abort a subshell
}

pass=0; fail=0; skip=0
for f in tests/pdo_net/cases/*.php; do
    n=$(basename "$f" .php)
    [[ -n "$FILTER" && "$n" != *"$FILTER"* ]] && continue
    case "$n" in pgsql_*) dsn="$PDO_PG_DSN" ;; mysql_native*) dsn="$PDO_MY_NATIVE_DSN" ;; mysql_*) dsn="$PDO_MY_DSN" ;; *) echo "bad case name $n: must start with pgsql_ or mysql_" >&2; exit 2 ;; esac
    if [ -z "$dsn" ] || ! reachable "$dsn"; then echo "SKIP $n (server unreachable)"; skip=$((skip+1)); continue; fi
    # a sibling <case>.needs lists further host:port servers the case talks to (one per line)
    down=""
    if [ -f "${f%.php}.needs" ]; then while read -r hp; do [ -n "$hp" ] && ! reachable "host=${hp%%:*};port=${hp##*:}" && down="$hp"; done < "${f%.php}.needs"; fi
    if [ -n "$down" ]; then echo "SKIP $n ($down unreachable)"; skip=$((skip+1)); continue; fi
    # Manticore-only cases (Async\ has no oracle) carry a hand-written <case>.expected instead of php output
    if [ ! -f "${f%.php}.expected" ] && grep -q 'Async\\' "$f"; then echo "FAIL $n (Async\ case without a hand-written ${n}.expected)"; fail=$((fail+1)); continue; fi
    pre="${f%.php}.pre"   # optional hook: server state a case needs before EACH run (php and native)
    if [ -x "$pre" ] && ! "$pre"; then echo "SKIP $n (pre-hook failed)"; skip=$((skip+1)); continue; fi
    if [ -f "${f%.php}.expected" ]; then exp=$(cat "${f%.php}.expected"); else exp=$(php -d xdebug.mode=off "$f" 2>&1); fi
    if ! bin/manticore compile "$f" -o "$WORK/$n" >"$WORK/$n.log" 2>&1; then
        echo "FAIL $n (compile)"; tail -n 20 "$WORK/$n.log"; fail=$((fail+1)); continue
    fi
    if [ -x "$pre" ] && ! "$pre"; then echo "SKIP $n (pre-hook failed)"; skip=$((skip+1)); continue; fi
    act=$("$WORK/$n" 2>&1)
    # both sides printing the same refusal is a vacuous pass: only a case marked `# expects-connect-failure` may show one
    if ! grep -q "expects-connect-failure" "$f" && grep -Eq "\[200[23]\]|Connection refused|could not connect|Connection to server" <<<"$exp$act"; then
        echo "FAIL $n (unexpected connection failure in the output, server probed reachable)"; fail=$((fail+1)); continue
    fi
    if [ "$exp" = "$act" ]; then echo "PASS $n"; pass=$((pass+1))
    else echo "FAIL $n"; echo "--- php"; echo "$exp" | head -n 20; echo "--- native"; echo "$act" | head -n 20; fail=$((fail+1)); fi
done
# Hostile-server cases: a fake server (Zend php) per mode, the compiled client, hand-written expectations.
if [[ -z "$FILTER" || "pgsql_hostile" == *"$FILTER"* ]]; then
    H=tests/pdo_net/hostile
    bin/manticore compile $H/client.php -o "$WORK/hostile" >"$WORK/hostile.log" 2>&1 || { echo "FAIL pgsql_hostile (compile)"; tail -n 20 "$WORK/hostile.log"; fail=$((fail+1)); }
    if [ -x "$WORK/hostile" ]; then
        act=""
        for m in huge short authok_after_sasl sasl_final_unsolicited truncated_datarow truncated_rowdesc copy_in paramdesc_truncated paramdesc_mismatch datarow_overrun ext_copy_in ext_copy_out big_slow oid_miss over_cap drip ignore_flood oid_flood bytea_odd bytea_nonhex bytea_escape bytea_escape_bad int_garbage int_max int_min int_overflow int_underflow float_lead float_trail float_minus float_plus idle_slow idle_explicit stall_mid; do
            rm -f "$WORK/port"
            php -d xdebug.mode=off $H/server.php $m "$WORK/port" >/dev/null 2>&1 &
            sp=$!
            for _ in $(seq 1 100); do [ -s "$WORK/port" ] && break; kill -0 $sp 2>/dev/null || break; sleep 0.1; done
            act+=$("$WORK/hostile" $m "$(cat "$WORK/port")" 2>&1)$'\n'
            kill $sp 2>/dev/null; wait $sp 2>/dev/null
        done
        if [ "$act" = "$(cat $H/hostile.expected)"$'\n' ]; then echo "PASS pgsql_hostile"; pass=$((pass+1))
        else echo "FAIL pgsql_hostile"; diff <(echo "$act") $H/hostile.expected | head -n 20; fail=$((fail+1)); fi
    fi
fi
# The same fake-server harness speaking the MySQL protocol (server_mysql.php), one mode per connection.
if [[ -z "$FILTER" || "mysql_hostile" == *"$FILTER"* ]]; then
    H=tests/pdo_net/hostile
    bin/manticore compile $H/client.php -o "$WORK/hostile" >"$WORK/hostile.log" 2>&1 || { echo "FAIL mysql_hostile (compile)"; tail -n 20 "$WORK/hostile.log"; fail=$((fail+1)); }
    if [ -x "$WORK/hostile" ]; then
        act=""
        for m in huge badseq short_scramble proto9 unsolicited_plugin err_handshake drip switch_unknown switch_short switch_old switch_loop more_data_native eof_in_auth trunc_coldef trunc_coldef_fixed row_overrun row_trailing row_short colcount_absurd colcount_zero_len no_eof local_infile empty_pkt trunc_stream over_cap_query err_mid_rows bad_seq_query more_forever big_slow int_garbage int_empty int_max int_min int_overflow int_underflow uint_max uint_over float_lead float_trail float_minus float_plus bit_long bit_max row_huge_len row_neg_len coldef_huge_len auth_0105 auth_repeat03 auth_double04 auth_nonpem auth_badkey auth_bigkey idle_slow idle_explicit stall_mid; do
            rm -f "$WORK/port"
            php -d xdebug.mode=off $H/server.php $m "$WORK/port" mysql >/dev/null 2>&1 &
            sp=$!
            for _ in $(seq 1 100); do [ -s "$WORK/port" ] && break; kill -0 $sp 2>/dev/null || break; sleep 0.1; done
            act+=$("$WORK/hostile" $m "$(cat "$WORK/port")" mysql 2>&1)$'\n'
            kill $sp 2>/dev/null; wait $sp 2>/dev/null
        done
        if [ "$act" = "$(cat $H/hostile_mysql.expected)"$'\n' ]; then echo "PASS mysql_hostile"; pass=$((pass+1))
        else echo "FAIL mysql_hostile"; diff <(echo "$act") $H/hostile_mysql.expected | head -n 20; fail=$((fail+1)); fi
    fi
fi
echo "pass=$pass fail=$fail skip=$skip"
[ "$fail" = 0 ]
