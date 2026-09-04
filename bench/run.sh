#!/usr/bin/env bash
# Optional micro-benchmark harness: compiles each bench/cases/*.php with
# manticore and times native vs the `php` interpreter (best-of-3 wall clock).
# Verifies output parity first — a mismatch is reported and not timed.
#
#   bash bench/run.sh            # all cases
#   bash bench/run.sh -k sort    # only cases whose name matches "sort"
#   REPS=5 bash bench/run.sh     # best-of-5 instead of best-of-3
#   MEM=0 bash bench/run.sh      # skip the max-RSS columns (wall-clock only)
#   LEAK=1 bash bench/run.sh     # leak table instead of the timing table
#
# A case carrying `@php-skip` is timed natively only — it uses prelude-only
# classes (Http\, Buffer\) or prints timings, so there is no parity to check.
#
# LEAK=1 answers a question the `rss-n` column cannot: peak RSS of ONE run does
# not separate a leak from a working set (`strcat` holds a 30 MB string on
# purpose, and the `@php-skip` cases have no `rss-p` to compare against). It runs
# each case at 1x and Nx work and reports the RSS DELTA:
#
#   * a case scales its own iteration count by `$argc`, so the harness scales it
#     by passing dummy args — no per-case knowledge here (SCALE=4 for a 4x arm);
#   * a case with no `$argc` cannot be scaled and reads `n/a`;
#   * `@rss-scales` marks a case whose WORKING SET legitimately grows with the
#     iteration count (`strcat`) — it reads `grows(exp)`, never LEAK;
#   * a constant working set whose RSS tracks the iteration count IS the leak,
#     and the ratio lands near SCALE — confirmed against a THIRD point at 2x
#     SCALE, since a pool high-water grows once and then plateaus.
#   LEAKPROF=1 additionally recompiles each LEAK case with MANTICORE_PROFILE=1
#   and prints the alloc-vs-release counter imbalance, which attributes the leak
#   to a call-site class (capture / element / prop / …).
#
# ⚠ The small-object pool never returns memory to the OS (a push-only per-class
# free list), so pool high-water rides in every number here. An honest pool A/B
# is TWO COLD SEEDS with MANTICORE_POOL=0 — the bodies are linkonce_odr, so a
# per-case env var does NOT give a control build.
#
# Not part of any gate — run it by hand to refresh the perf snapshot.
set -u

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/.." && pwd)"
# MANT= points the harness at another checkout's compiler — the honest way to
# A/B a codegen change is to measure the two binaries, not to rebuild in place.
mant="${MANT:-$root/bin/manticore}"
cases_dir="$here/cases"
out_dir="${TMPDIR:-/tmp}/manticore_bench.$$"
mkdir -p "$out_dir"
trap 'rm -rf "$out_dir"' EXIT

filter="${2:-}"
[ "${1:-}" = "-k" ] && filter="${2:-}"
reps="${REPS:-3}"
php_bin="${PHP:-php}"
# RSS columns are ON by default (one extra `/usr/bin/time -l` run per side);
# set MEM=0 to skip them for a faster wall-only run.
mem="${MEM:-1}"
leak="${LEAK:-0}"
scale="${SCALE:-2}"
leakprof="${LEAKPROF:-0}"

if [ ! -x "$mant" ]; then echo "no $mant — run bin/build first" >&2; exit 1; fi

# min wall-clock (seconds) of $reps runs of "$@", via /usr/bin/time -p.
best_time() {
    local best="" t
    for _ in $(seq 1 "$reps"); do
        t=$( { /usr/bin/time -p "$@" >/dev/null; } 2>&1 | awk '/^real/{print $2}' )
        if [ -z "$best" ] || awk "BEGIN{exit !($t < $best)}"; then best="$t"; fi
    done
    echo "$best"
}

# max RSS in MB of one run of "$@" (macOS `time -l` reports bytes).
max_rss_mb() {
    { /usr/bin/time -l "$@" >/dev/null; } 2>&1 \
        | awk '/maximum resident set size/{printf "%.1f", $1/1048576}'
}

# max RSS in BYTES of one run of "$@" — macOS `time -l` (bytes) or GNU
# `time -v` (kbytes). Empty when neither is available (the Debian toolchain
# image has no /usr/bin/time at all).
max_rss_bytes() {
    local out
    out=$( { /usr/bin/time -l "$@" >/dev/null; } 2>&1 )
    if printf '%s\n' "$out" | grep -q 'maximum resident set size'; then
        printf '%s\n' "$out" | awk '/maximum resident set size/{print $1; exit}'
        return
    fi
    out=$( { /usr/bin/time -v "$@" >/dev/null; } 2>&1 )
    printf '%s\n' "$out" \
        | awk -F: '/Maximum resident set size/{gsub(/[ \t]/,"",$2); print $2*1024; exit}'
}

shopt -s nullglob

# ── LEAK mode: 1x vs Nx RSS, no timing ──────────────────────────────────────
if [ "$leak" = "1" ]; then
    if [ -z "$(max_rss_bytes true)" ]; then
        echo "no usable /usr/bin/time — LEAK mode needs max-RSS reporting" >&2
        exit 1
    fi
    scale_args=()
    for _ in $(seq 2 "$scale"); do scale_args+=("x"); done
    printf '%-20s %10s %10s %10s %8s   %s\n' \
        "case" "rss-1x(MB)" "rss-${scale}x(MB)" "delta(MB)" "ratio" "verdict"
    printf '%s\n' "--------------------------------------------------------------------------"
    leaks=0; checked=0
    for f in "$cases_dir"/*.php; do
        name="$(basename "$f" .php)"
        [ -n "$filter" ] && [[ "$name" != *"$filter"* ]] && continue
        bin="$out_dir/$name"
        if ! "$mant" compile "$f" -o "$bin" >"$out_dir/$name.cerr" 2>&1; then
            printf '%-20s %10s %10s %10s %8s   %s\n' "$name" "-" "-" "-" "-" "COMPILE-FAIL"
            continue
        fi
        # No `$argc` in the case ⇒ its work cannot be scaled ⇒ no two points.
        if ! grep -q '\$argc' "$f"; then
            printf '%-20s %10s %10s %10s %8s   %s\n' "$name" "-" "-" "-" "-" "n/a (no \$argc)"
            continue
        fi
        b1="$(max_rss_bytes "$bin")"
        bn="$(max_rss_bytes "$bin" "${scale_args[@]}")"
        if [ -z "$b1" ] || [ -z "$bn" ]; then
            printf '%-20s %10s %10s %10s %8s   %s\n' "$name" "-" "-" "-" "-" "RSS-FAIL"
            continue
        fi
        r1="$(awk "BEGIN{printf \"%.1f\", $b1/1048576}")"
        rn="$(awk "BEGIN{printf \"%.1f\", $bn/1048576}")"
        dl="$(awk "BEGIN{printf \"%.1f\", ($bn-$b1)/1048576}")"
        ra="$(awk "BEGIN{ if ($b1>0) printf \"%.2f\", $bn/$b1; else print \"-\" }")"
        checked=$((checked + 1))
        if grep -q '@rss-scales' "$f"; then
            verdict="grows(exp)"
        elif awk "BEGIN{exit !(($bn-$b1) > 524288 && $bn > 1.2*$b1)}"; then
            # Two points cannot tell a leak from a bounded high-water: a
            # size-classed pool that never returns memory, or a free list warming
            # up, grows once and then stops. Confirm with a THIRD point at twice
            # the scale — a leak keeps paying, a high-water plateaus.
            # (`assoc_small` is exactly this: 7.8 → 9.7 MB and then 9.7 for ever.)
            wide_args=()
            for _ in $(seq 2 $((scale * 2))); do wide_args+=("x"); done
            bw="$(max_rss_bytes "$bin" "${wide_args[@]}")"
            if [ -n "$bw" ] && awk "BEGIN{exit !(($bw-$bn) > 0.5*($bn-$b1))}"; then
                verdict="LEAK"
                leaks=$((leaks + 1))
            else
                verdict="plateau"
            fi
        else
            verdict="ok"
        fi
        printf '%-20s %10s %10s %10s %8s   %s\n' "$name" "$r1" "$rn" "$dl" "$ra" "$verdict"
        # Attribution: the atexit counters of a profile build. A leak shows as
        # alloc/retain running ahead of release on one channel.
        if [ "$leakprof" = "1" ] && [ "$verdict" = "LEAK" ]; then
            pbin="$out_dir/$name.prof"
            if MANTICORE_PROFILE=1 "$mant" compile "$f" -o "$pbin" \
                    >"$out_dir/$name.perr" 2>&1; then
                "$pbin" >/dev/null 2>"$out_dir/$name.prof.txt"
                awk '/^\[PROFILE\]/{sub(/^\[PROFILE\] /,""); printf "      %s\n", $0}' \
                    "$out_dir/$name.prof.txt"
            else
                echo "      (profile build failed — see $out_dir/$name.perr)"
            fi
        fi
    done
    printf '%s\n' "--------------------------------------------------------------------------"
    echo "scaled $checked · LEAK on $leaks · scale ${scale}x"
    exit 0
fi

if [ "$mem" = "1" ]; then
    printf '%-20s %10s %10s %9s %10s %10s   %s\n' "case" "native(s)" "php(s)" "speedup" "rss-n(MB)" "rss-p(MB)" "parity"
    printf '%s\n' "----------------------------------------------------------------------------------------"
else
    printf '%-20s %10s %10s %9s   %s\n' "case" "native(s)" "php(s)" "speedup" "parity"
    printf '%s\n' "-------------------------------------------------------------------"
fi

total=0; faster=0; diffs=0; diffnames=""
for f in "$cases_dir"/*.php; do
    name="$(basename "$f" .php)"
    [ -n "$filter" ] && [[ "$name" != *"$filter"* ]] && continue
    bin="$out_dir/$name"

    if ! "$mant" compile "$f" -o "$bin" >"$out_dir/$name.cerr" 2>&1; then
        printf '%-20s %10s %10s %9s   %s\n' "$name" "-" "-" "-" "COMPILE-FAIL"
        continue
    fi

    # `@php-skip` in the case: nothing to compare against (it uses prelude-only
    # classes, or prints timings). Time the native side alone rather than
    # reporting a DIFF that means nothing.
    if grep -q '@php-skip' "$f"; then
        nt="$(best_time "$bin")"
        if [ "$mem" = "1" ]; then
            printf '%-20s %10s %10s %9s %10s %10s   %s\n' \
                "$name" "$nt" "-" "-" "$(max_rss_mb "$bin")" "-" "php-skip"
        else
            printf '%-20s %10s %10s %9s   %s\n' "$name" "$nt" "-" "-" "php-skip"
        fi
        total=$((total + 1))
        continue
    fi

    n_out="$("$bin")"
    p_out="$("$php_bin" "$f" 2>/dev/null)"
    if [ "$n_out" != "$p_out" ]; then
        printf '%-20s %10s %10s %9s   %s\n' "$name" "-" "-" "-" "DIFF"
        diffs=$((diffs + 1)); diffnames="$diffnames $name"
        continue
    fi

    nt="$(best_time "$bin")"
    pt="$(best_time "$php_bin" "$f")"
    sp="$(awk "BEGIN{ if ($nt>0) printf \"%.1fx\", $pt/$nt; else print \"inf\" }")"
    total=$((total + 1))
    awk "BEGIN{exit !($pt > $nt)}" && faster=$((faster + 1))
    if [ "$mem" = "1" ]; then
        rn="$(max_rss_mb "$bin")"
        rp="$(max_rss_mb "$php_bin" "$f")"
        printf '%-20s %10s %10s %9s %10s %10s   %s\n' "$name" "$nt" "$pt" "$sp" "$rn" "$rp" "ok"
    else
        printf '%-20s %10s %10s %9s   %s\n' "$name" "$nt" "$pt" "$sp" "ok"
    fi
done
printf '%s\n' "-------------------------------------------------------------------"
echo "ran $total · native faster on $faster · php $($php_bin -r 'echo PHP_VERSION;')"
# A parity DIFF is a WRONG ANSWER, not a slow one. It used to print in a column
# and exit 0, so `json_objects` sat there returning false from every
# json_encode($object) for as long as the row existed. Fail the run instead.
if [ "$diffs" -gt 0 ]; then
    printf '\nPARITY FAILURES (%d):%s\n' "$diffs" "$diffnames" >&2
    exit 1
fi
