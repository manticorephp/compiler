#!/bin/bash
# pdo_net: byte parity of compiled PDO network drivers against php, on live servers.
#   tests/pdo_net/run.sh [-k substr] [--server mysql|mariadb] [--no-pgsql]
# --no-pgsql leaves out every pgsql_* case and the pgsql hostile run (CI's second server row repeats nothing the first one proved).
# --server picks the server the mysql_* cases run against (default mysql = mc-my :33060, mariadb = mc-maria :33061); the oracle is live php on THAT server.
# A case header line `// servers: mysql` (default: both) limits a case; the other server SKIPs it with the reason.
# Cases named pgsql_* / mysql_* use PDO_PG_DSN / PDO_MY_DSN (mysql_native : PDO_MY_NATIVE_DSN); SKIP when the server is unreachable.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
FILTER=""
SERVER=mysql
NOPG=0
while [ $# -gt 0 ]; do
    case "$1" in
        -k) FILTER="${2:-}"; shift 2 ;;
        --server) SERVER="${2:-}"; shift 2 ;;
        --no-pgsql) NOPG=1; shift ;;
        *) echo "usage: run.sh [-k substr] [--server mysql|mariadb] [--no-pgsql]" >&2; exit 2 ;;
    esac
done
case "$SERVER" in mysql) MY_PORT=33060 ;; mariadb) MY_PORT=33061 ;; *) echo "unknown --server $SERVER (mysql|mariadb)" >&2; exit 2 ;; esac
export PDO_PG_DSN="${PDO_PG_DSN-pgsql:host=127.0.0.1;port=54320;dbname=d}"
export PDO_PG_SSL_DSN="${PDO_PG_SSL_DSN-pgsql:host=127.0.0.1;port=54323;dbname=d}"
export PDO_MY_DSN="${PDO_MY_DSN-mysql:host=127.0.0.1;port=$MY_PORT;dbname=d}"
PDO_MY_DSN_DEFAULT="$PDO_MY_DSN"
export PDO_MY_NATIVE_DSN="${PDO_MY_NATIVE_DSN-mysql:host=127.0.0.1;port=33062;dbname=d}"
WORK="$ROOT/tests/pdo_net/.work"
mkdir -p "$WORK"
# TLS material: pg's comes from up.sh (.certs); mysql's CA is the one mc-my generated for itself, copied out per run
# the hostile TLS server (ssl_tls12) needs a certificate of its own: hermetic, generated per run
bash "$ROOT/tests/pdo_net/mkcerts.sh" "$WORK/tls" && export MC_TLS_DIR="$WORK/tls"
export PDO_SSL_DIR="$ROOT/tests/pdo_net/.certs"
if command -v docker >/dev/null 2>&1 && [ "$(docker inspect -f '{{.State.Running}}' mc-my 2>/dev/null)" = true ]; then
    [ -z "${PDO_MY_SSL_CA:-}" ] && docker cp mc-my:/var/lib/mysql/ca.pem "$WORK/my_ca.pem" >/dev/null 2>&1 && export PDO_MY_SSL_CA="$WORK/my_ca.pem"
fi

reachable() { # dsn
    local h p
    h=$(sed -n 's/.*host=\([^;]*\).*/\1/p' <<<"$1"); p=$(sed -n 's/.*port=\([^;]*\).*/\1/p' <<<"$1")
    [ -n "$h" ] && [ -n "$p" ] && (exec 3<>"/dev/tcp/$h/$p") 2>/dev/null   # no second command: under bash 3.2 a failed redirect does not abort a subshell
}

pass=0; fail=0; skip=0
real=0        # cases that ran against a server (the hostile fake-server runs need none and prove nothing about the real ones)
unreach=0     # SKIPs because a server was not reachable; MC_PDO_STRICT=1 (CI) turns any of them into a failure
# a started case's .post hook runs on ANY exit (Ctrl-C included) unless the case already ran it: PENDING_POST names the one still owed
PENDING_POST=""
run_pending_post() { local p="$PENDING_POST"; PENDING_POST=""; [ -n "$p" ] && [ -x "$p" ] && "$p"; return 0; }
trap 'run_pending_post; rm -rf "$WORK"' EXIT
trap 'exit 130' INT TERM
for f in tests/pdo_net/cases/*.php; do
    n=$(basename "$f" .php)
    [[ -n "$FILTER" && "$n" != *"$FILTER"* ]] && continue
    [[ "$NOPG" = 1 && "$n" == pgsql_* ]] && continue
    case "$n" in pgsql_*) dsn="$PDO_PG_DSN" ;; mysql_native) dsn="$PDO_MY_NATIVE_DSN" ;; mysql_*) dsn="$PDO_MY_DSN" ;; *) echo "bad case name $n: must start with pgsql_ or mysql_" >&2; exit 2 ;; esac
    # a sibling <case>.dsn names the DSN to use instead (cases that need a server of their own: mc-my-iso, :33065)
    [ -f "${f%.php}.dsn" ] && dsn=$(cat "${f%.php}.dsn") && export PDO_MY_DSN="$dsn" || export PDO_MY_DSN="${PDO_MY_DSN_DEFAULT}"
    # `// servers: mysql` in the first lines limits a case to those servers (comma separated; default both)
    only=$(head -n 6 "$f" | sed -n 's|^// servers: *||p' | tr -d ' ')
    if [ -n "$only" ] && [[ ",$only," != *",$SERVER,"* ]]; then echo "SKIP $n (servers: $only, running $SERVER)"; skip=$((skip+1)); continue; fi
    if [ -z "$dsn" ] || ! reachable "$dsn"; then echo "SKIP $n (server unreachable)"; skip=$((skip+1)); unreach=$((unreach+1)); continue; fi
    # a sibling <case>.needs lists further host:port servers the case talks to (one per line)
    down=""
    if [ -f "${f%.php}.needs" ]; then while read -r hp; do [ -n "$hp" ] && ! reachable "host=${hp%%:*};port=${hp##*:}" && down="$hp"; done < "${f%.php}.needs"; fi
    if [ -n "$down" ]; then echo "SKIP $n ($down unreachable)"; skip=$((skip+1)); unreach=$((unreach+1)); continue; fi
    # Manticore-only cases (Async\ has no oracle) carry a hand-written <case>.expected instead of php output
    if [ ! -f "${f%.php}.expected" ] && grep -q 'Async\\' "$f"; then echo "FAIL $n (Async\ case without a hand-written ${n}.expected)"; fail=$((fail+1)); continue; fi
    pre="${f%.php}.pre"   # optional hook: server state a case needs before EACH run (php and native)
    post="${f%.php}.post"; PENDING_POST="$post" # optional hook: undoes it after EACH run (also a global the test user cannot restore itself)
    if [ -x "$pre" ] && ! "$pre"; then echo "SKIP $n (pre-hook failed)"; skip=$((skip+1)); unreach=$((unreach+1)); run_pending_post; continue; fi
    prc=0
    if [ -f "${f%.php}.expected" ]; then exp=$(cat "${f%.php}.expected"); else exp=$(php -d xdebug.mode=off "$f" 2>&1); prc=$?; fi
    [ -x "$post" ] && "$post"
    PENDING_POST="$post"
    if ! bin/manticore compile "$f" -o "$WORK/$n" >"$WORK/$n.log" 2>&1; then
        echo "FAIL $n (compile)"; tail -n 20 "$WORK/$n.log"; fail=$((fail+1)); PENDING_POST=""; continue
    fi
    if [ -x "$pre" ] && ! "$pre"; then echo "SKIP $n (pre-hook failed)"; skip=$((skip+1)); unreach=$((unreach+1)); continue; fi
    act=$("$WORK/$n" 2>&1)
    nrc=$?
    run_pending_post
    # the exit status is part of the behaviour: a crash after identical output (a SIGSEGV in a destructor) is not a pass.
    # Oracle cases compare with php's status; hand-written ones expect 0 unless the case says `// expects-exit-nonzero`.
    if [ ! -f "${f%.php}.expected" ]; then want=$prc; elif grep -q 'expects-exit-nonzero' "$f"; then want=$nrc; else want=0; fi
    if [ "$nrc" != "$want" ]; then echo "FAIL $n (native exit status $nrc, expected $want)"; fail=$((fail+1)); continue; fi
    # a case that printed its own assertion failure fails even when php printed the same
    if grep -q 'ASSERTION FAILED' <<<"$exp$act"; then echo "FAIL $n (the case reports a failed assertion)"; fail=$((fail+1)); continue; fi
    # both sides printing the same refusal is a vacuous pass: only a case marked `# expects-connect-failure` may show one
    if ! grep -q "expects-connect-failure" "$f" && grep -Eq "\[200[23]\]|Connection refused|could not connect|Connection to server" <<<"$exp$act"; then
        echo "FAIL $n (unexpected connection failure in the output, server probed reachable)"; fail=$((fail+1)); continue
    fi
    # a key file libpq / the driver refuses as too open: php and native refuse alike, so the case would pass without testing the client-certificate lines.
    # `// key-perms-refusals: N` in a case names the deliberate ones.
    kp=$(grep -c 'has group or world access' <<<"$act"); okp=$(sed -n 's|^// key-perms-refusals: *||p' "$f" | head -n 1)
    if [ "$kp" -gt "${okp:-0}" ]; then echo "FAIL $n ($kp key-permission refusal(s), ${okp:-0} expected: the key file is too open for the check to mean anything)"; fail=$((fail+1)); continue; fi
    if [ "$exp" = "$act" ]; then echo "PASS $n"; pass=$((pass+1)); real=$((real+1))
    else echo "FAIL $n"; echo "--- diff (php < > native)"; diff <(echo "$exp") <(echo "$act") | head -n 40 | cut -c1-300; fail=$((fail+1)); fi
done
# Hostile-server cases: a fake server (Zend php) per mode, the compiled client, hand-written expectations.
if [[ "$NOPG" != 1 && ( -z "$FILTER" || "pgsql_hostile" == *"$FILTER"* ) ]]; then
    H=tests/pdo_net/hostile
    bin/manticore compile $H/client.php -o "$WORK/hostile" >"$WORK/hostile.log" 2>&1 || { echo "FAIL pgsql_hostile (compile)"; tail -n 20 "$WORK/hostile.log"; fail=$((fail+1)); }
    if [ -x "$WORK/hostile" ]; then
        act=""
        for m in huge short authok_after_sasl sasl_final_unsolicited truncated_datarow truncated_rowdesc copy_in paramdesc_truncated paramdesc_mismatch datarow_overrun ext_copy_in ext_copy_out notify_trunc notify_unterminated notify_over_cap notify_flood notify_bytes notify_big_d copy_out_cap copy_d_outside copy_in_err copy_slow_commit big_slow oid_miss over_cap drip ignore_flood oid_flood bytea_odd bytea_nonhex bytea_escape bytea_escape_bad int_garbage int_max int_min int_overflow int_underflow float_lead float_trail float_minus float_plus idle_slow idle_between idle_explicit stall_mid ssl_n ssl_e ssl_byte ssl_eof ssl_extra ssl_late ssl_garbage ssl_silent ssl_close ssl_drip ssl_tls12 fb_batch_ok fb_batch_rows_over fb_batch_suspended_body fb_batch_more_over fb_batch_overrun2 fb_batch_trunc fb_batch_early_z fb_batch_stall_mid fb_batch_err_second fb_batch_close_slow fb_batch_close_idle_default; do
            rm -f "$WORK/port"
            php -d xdebug.mode=off $H/server.php $m "$WORK/port" >/dev/null 2>&1 &
            sp=$!
            for _ in $(seq 1 100); do [ -s "$WORK/port" ] && break; kill -0 $sp 2>/dev/null || break; sleep 0.1; done
            hout=$("$WORK/hostile" $m "$(cat "$WORK/port")" 2>&1); hrc=$?
            act+="$hout"$'\n'; [ "$hrc" = 0 ] || act+="$m: native exit status $hrc"$'\n'
            kill $sp 2>/dev/null; wait $sp 2>/dev/null
        done
        if [ "$act" = "$(cat $H/hostile.expected)"$'\n' ]; then echo "PASS pgsql_hostile"; pass=$((pass+1))
        else echo "FAIL pgsql_hostile"; diff <(echo "$act") $H/hostile.expected | head -n 20; fail=$((fail+1)); fi
    fi
fi
# The same fake-server harness speaking the MySQL protocol (server_mysql.php), one mode per connection.
if [[ "$SERVER" = mysql && ( -z "$FILTER" || "mysql_hostile" == *"$FILTER"* ) ]]; then
    H=tests/pdo_net/hostile
    bin/manticore compile $H/client.php -o "$WORK/hostile" >"$WORK/hostile.log" 2>&1 || { echo "FAIL mysql_hostile (compile)"; tail -n 20 "$WORK/hostile.log"; fail=$((fail+1)); }
    if [ -x "$WORK/hostile" ]; then
        act=""
        for m in huge badseq short_scramble proto9 unsolicited_plugin err_handshake drip switch_unknown switch_short switch_old switch_loop more_data_native eof_in_auth trunc_coldef trunc_coldef_fixed row_overrun row_trailing row_short colcount_absurd colcount_zero_len no_eof local_infile empty_pkt trunc_stream over_cap_query err_mid_rows bad_seq_query more_forever big_slow int_garbage int_empty int_max int_min int_overflow int_underflow uint_max uint_over float_lead float_trail float_minus float_plus bit_long bit_max row_huge_len row_neg_len coldef_huge_len auth_0105 auth_repeat03 auth_double04 auth_nonpem auth_badkey auth_bigkey idle_slow idle_between idle_explicit stall_mid ssl_nocap ssl_extra ssl_garbage ssl_silent ssl_close ssl_drip np_ok np_close_once np_cols_absurd np_params_absurd np_trunc_ok np_prep_long np_params_fewer np_params_more np_cols_more np_params_mismatch np_err_prepare np_err_exec np_row_overrun np_bitmap_short np_dt_len np_time_len np_time_len3 np_dt_overrun np_huge_lenenc np_neg_lenenc np_int_short np_trailing np_hdr np_unexpected np_badseq np_colcount_absurd np_no_eof np_float_odd np_nan np_year_short np_idle_slow np_idle_explicit np_stall_mid us_row_overrun us_row_trailing us_row_short us_row_huge_len us_row_neg_len us_err_mid_rows us_rows_trunc us_rows_noeof us_rows_badseq us_drain_bad us_drain_dtor us_drain_slow us_drain_idle_default us_more_after_eof us_more_bad_header us_drain_bad_next us_int_garbage us_bit_long us_stall_mid us_np_ok us_np_row_overrun us_np_bitmap_short us_np_trailing us_np_dt_overrun us_np_huge_lenenc us_np_stall_mid kill_stall; do
            rm -f "$WORK/port"
            php -d xdebug.mode=off $H/server.php $m "$WORK/port" mysql >/dev/null 2>&1 &
            sp=$!
            for _ in $(seq 1 100); do [ -s "$WORK/port" ] && break; kill -0 $sp 2>/dev/null || break; sleep 0.1; done
            hout=$("$WORK/hostile" $m "$(cat "$WORK/port")" mysql 2>&1); hrc=$?
            act+="$hout"$'\n'; [ "$hrc" = 0 ] || act+="$m: native exit status $hrc"$'\n'
            # the KILL connection must not cancel itself: exactly two connections (the client's and its cancel hook)
            if [ "$m" = kill_stall ]; then sleep 0.6; act+="kill_stall: connections $(cat "$WORK/port.conns" 2>/dev/null)"$'\n'; fi
            kill $sp 2>/dev/null; wait $sp 2>/dev/null
        done
        if [ "$act" = "$(cat $H/hostile_mysql.expected)"$'\n' ]; then echo "PASS mysql_hostile"; pass=$((pass+1))
        else echo "FAIL mysql_hostile"; diff <(echo "$act") $H/hostile_mysql.expected | head -n 20; fail=$((fail+1)); fi
    fi
fi
echo "pass=$pass fail=$fail skip=$skip"
# a run where every case SKIPped (a server that is down) proved nothing: it must not look green, hostile passes included
if [ "$real" = 0 ] && [ "$skip" != 0 ]; then echo "FAIL: nothing ran against a server ($skip skipped; is the server up? tests/pdo_net/up.sh)" >&2; exit 1; fi
# strict (CI): the servers are supposed to be there, so an unreachable one is a failure, not a skip
if [ "${MC_PDO_STRICT:-0}" = 1 ] && [ "$unreach" != 0 ]; then echo "FAIL: $unreach case(s) skipped because a server was unreachable (MC_PDO_STRICT=1)" >&2; exit 1; fi
[ "$fail" = 0 ]
