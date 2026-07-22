#!/usr/bin/env bash
# Optional micro-benchmark harness: compiles each bench/cases/*.php with
# manticore and times native vs the `php` interpreter (best-of-3 wall clock).
# Verifies output parity first — a mismatch is reported and not timed.
#
#   bash bench/run.sh            # all cases
#   bash bench/run.sh -k sort    # only cases whose name matches "sort"
#   REPS=5 bash bench/run.sh     # best-of-5 instead of best-of-3
#   MEM=0 bash bench/run.sh      # skip the max-RSS columns (wall-clock only)
#
# Not part of any gate — run it by hand to refresh the perf snapshot.
set -u

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/.." && pwd)"
mant="$root/bin/manticore"
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

if [ "$mem" = "1" ]; then
    printf '%-18s %10s %10s %9s %10s %10s   %s\n' "case" "native(s)" "php(s)" "speedup" "rss-n(MB)" "rss-p(MB)" "parity"
    printf '%s\n' "----------------------------------------------------------------------------------------"
else
    printf '%-18s %10s %10s %9s   %s\n' "case" "native(s)" "php(s)" "speedup" "parity"
    printf '%s\n' "-------------------------------------------------------------------"
fi

shopt -s nullglob
total=0; faster=0
for f in "$cases_dir"/*.php; do
    name="$(basename "$f" .php)"
    [ -n "$filter" ] && [[ "$name" != *"$filter"* ]] && continue
    bin="$out_dir/$name"

    if ! "$mant" compile "$f" -o "$bin" >"$out_dir/$name.cerr" 2>&1; then
        printf '%-18s %10s %10s %9s   %s\n' "$name" "-" "-" "-" "COMPILE-FAIL"
        continue
    fi

    n_out="$("$bin")"
    p_out="$("$php_bin" "$f" 2>/dev/null)"
    if [ "$n_out" != "$p_out" ]; then
        printf '%-18s %10s %10s %9s   %s\n' "$name" "-" "-" "-" "DIFF"
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
        printf '%-18s %10s %10s %9s %10s %10s   %s\n' "$name" "$nt" "$pt" "$sp" "$rn" "$rp" "ok"
    else
        printf '%-18s %10s %10s %9s   %s\n' "$name" "$nt" "$pt" "$sp" "ok"
    fi
done
printf '%s\n' "-------------------------------------------------------------------"
echo "ran $total · native faster on $faster · php $($php_bin -r 'echo PHP_VERSION;')"
