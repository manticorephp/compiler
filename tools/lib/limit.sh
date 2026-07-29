# Shared time limit for anything a test harness runs. Sourced, not executed.
#
# Without a limit a liveness bug HANGS the harness instead of failing it: an
# unbounded write park (async_write_timeout, pre-fix) simply never came back, and
# the run had to be killed by hand. A hung suite reports nothing at all, which is
# strictly worse than a red case.
#
# `timeout(1)` is NOT a given — macOS ships no coreutils, so a dev host may have
# neither `timeout` nor `gtimeout`, and a bare `timeout 60 "$bin"` would be
# `command not found` on every case, i.e. a fake full-suite regression. perl is
# present on both hosts; the poll loop is the last resort. Resolved ONCE at source
# time — per case this costs nothing. MC_TIMEOUT_KIND forces one implementation,
# which is how the paths that this host cannot select are tested.

if [[ -n "${MC_TIMEOUT_KIND:-}" ]]; then
    TIMEOUT_KIND="$MC_TIMEOUT_KIND"
elif command -v timeout >/dev/null 2>&1; then
    TIMEOUT_KIND=timeout
elif command -v gtimeout >/dev/null 2>&1; then
    TIMEOUT_KIND=gtimeout
elif command -v perl >/dev/null 2>&1; then
    TIMEOUT_KIND=perl
else
    TIMEOUT_KIND=shell
fi

# mc_limit <seconds> <cmd> [args…]
#
# Yields the command's own exit status, or 124 on expiry — GNU timeout's code, so
# every implementation below agrees and the caller has one number to test. Never
# lets `set -e` fire: each path captures the status explicitly.
mc_limit() {
    local secs="$1"; shift
    local rc=0
    case "$TIMEOUT_KIND" in
        timeout|gtimeout)
            "$TIMEOUT_KIND" -k 2 "$secs" "$@" || rc=$?
            return $rc
            ;;
        perl)
            # fork+alarm rather than the shorter `alarm $s; exec @ARGV`: alarm
            # survives exec but SIGALRM's disposition does not, so that idiom kills
            # the child with signal 14 and reports 142. This returns a clean 124.
            perl -e '
                my $s = shift;
                my $pid = fork();
                exit 127 unless defined $pid;
                if ($pid == 0) { exec @ARGV; exit 127; }
                $SIG{ALRM} = sub {
                    kill "TERM", $pid;
                    select(undef, undef, undef, 0.5);
                    kill "KILL", $pid;
                    exit 124;
                };
                alarm $s;
                waitpid($pid, 0);
                my $st = $?;
                alarm 0;
                exit(($st & 127) ? 128 + ($st & 127) : $st >> 8);
            ' "$secs" "$@" || rc=$?
            return $rc
            ;;
    esac
    # Pure shell: background the child and poll it at 5 Hz.
    "$@" &
    local child=$!
    local ticks=0
    local limit=$(( secs * 5 ))
    while kill -0 "$child" 2>/dev/null; do
        if [[ $ticks -ge $limit ]]; then
            kill -TERM "$child" 2>/dev/null || true
            sleep 1
            kill -KILL "$child" 2>/dev/null || true
            wait "$child" 2>/dev/null || true
            return 124
        fi
        sleep 0.2
        ticks=$((ticks + 1))
    done
    wait "$child" || rc=$?
    return $rc
}
