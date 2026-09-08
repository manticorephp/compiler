#!/usr/bin/env bash
# cellguard (MANTICORE_CELLGUARD=1) census + regression guard.
# Runs the emitter-seam cell verifier over the AOT corpus under the ZEND host,
# so no self-build is needed. Prints a ranked violation census and asserts the
# positive case still fires.
#
# CELLGUARD_SUBSET: number of corpus cases to scan, 0 = all (default: 0).
# Set it to a small number for a fast smoke run before committing to the
# full ~1066-case corpus, which takes ~12-15 min under Zend (measured).
# MAX_ERR_BYTES: per-case stderr cap in bytes (default: 2000000). Override to
# a small value together with CELLGUARD_SUBSET to deliberately exercise the
# cap-kill path (see the "cap-killed" bucket below) without waiting for a
# real case to overrun the default 2 MB.
set -u
cd "$(dirname "$0")/.."

export MC_SRC="$PWD/src"
export MC_SIG="$PWD/lib/manticore_stdlib.o.sig"
export MANTICORE_PRELUDE="$PWD/prelude"
export MANTICORE_CELLGUARD=1

CELLGUARD_SUBSET="${CELLGUARD_SUBSET:-0}"

out=/tmp/cellguard_scan
rm -rf "$out"; mkdir -p "$out"
fail=0

# Cap any single per-case stderr capture (a compile that runs away must not
# fill the disk — see the recorded 66 GB single-file incident). This caps the
# WRITE itself via `ulimit -f` in a subshell around each php invocation, not a
# post-hoc `head -c` on an already-fully-written file — a `2> file` redirect
# writes the entire uncapped stream before anything downstream can truncate
# it, which is exactly the shape of the recorded incident. `ulimit -f` is in
# 512-byte blocks; round the byte cap up to a whole block.
MAX_ERR_BYTES="${MAX_ERR_BYTES:-2000000}"
MAX_ERR_BLOCKS=$(( (MAX_ERR_BYTES + 511) / 512 ))
# `ulimit -f` kills the writer with SIGXFSZ instead of truncating — the
# child's exit status alone (128+25=153 on both Linux and macOS) is not
# proof by itself (a platform could number the signal differently), so a
# cap-kill is also corroborated by the resulting file having reached this
# exact byte boundary (the block-granular cap rounds MAX_ERR_BYTES UP to a
# whole block, so a killed write lands at exactly this many bytes).
CAPPED_BYTES=$(( MAX_ERR_BLOCKS * 512 ))

ls tests/aot/cases/*.php | sort > "$out/all_cases.txt"
corpus_total=$(wc -l < "$out/all_cases.txt" | tr -d ' ')
if [ "$CELLGUARD_SUBSET" != "0" ] && [ "$CELLGUARD_SUBSET" -lt "$corpus_total" ]; then
    head -n "$CELLGUARD_SUBSET" "$out/all_cases.txt" > "$out/cases.txt"
    echo "── SUBSET RUN: $CELLGUARD_SUBSET of $corpus_total cases — NOT the full corpus ──"
else
    cp "$out/all_cases.txt" "$out/cases.txt"
fi

echo "── corpus census: raw violation lines per case (NOT the work list — a shared prelude body like usort/uksort/rsort counts once per case that pulls it in, so this ranks 'how much of the prelude did this case load', not where the bug lives; see the distinct-site ranking below) ──"
# NOTE: this loop's stdout feeds a pipeline (sort/tee below), which in POSIX
# shells runs the loop in a subshell — any variable set inside it is lost
# once the pipeline exits. So per-case bookkeeping (compile failures) is
# recorded to a file from inside the loop and only counted afterward.
: > "$out/compile_failures.txt"
: > "$out/cap_killed.txt"
while IFS= read -r f; do
    b=$(basename "$f" .php)
    (
        ulimit -f "$MAX_ERR_BLOCKS"
        exec php -d memory_limit=2048M tools/compile_user_mir.php "$f" \
            > /dev/null 2> "$out/$b.err"
    )
    rc=$?
    size=$(wc -c < "$out/$b.err" 2>/dev/null | tr -d ' ')
    [ -z "${size:-}" ] && size=0
    # INVARIANT: every case lands in EXACTLY ONE of three disjoint buckets —
    # cap-killed, compile-failed, classified. Enforced two ways: (a) this is
    # an if/elif/else, so only one branch runs per case; (b) the case's
    # captured .err is RENAMED to a bucket-specific extension in the
    # cap-killed and compile-failed branches, so every later section that
    # globs "$out"/*.err (the sink-kind histogram, the site work list, the
    # aggregate boxed/opaque/raw/unchecked sum) can PHYSICALLY only ever see
    # a classified case's data — not by omitting to look at the others, but
    # because they are no longer named *.err. Do not reorder these checks or
    # add another path that writes/reads "$out/$b.err" after this point.
    #
    # Check cap-killed FIRST: `ulimit -f` kills the writer with SIGXFSZ
    # mid-emission rather than truncating, and a killed rc looks exactly like
    # an ordinary nonzero compile failure unless checked for specifically —
    # that conflation, and the resulting risk of one case counted as BOTH an
    # excluded compile failure and a scored census entry, is the bug this
    # fixes. A cap-kill is neither "clean" nor "never compiled": it died
    # WHILE emitting a large volume of real cellguard output, so its count is
    # a truncated undercount, not an absence of data.
    #
    # The two checks below are ALTERNATIVES, not corroboration — either
    # alone is sufficient evidence, and they are gated on `rc != 0` together
    # (an OR of the two, not an AND) on purpose:
    #   - `rc == 0` is excluded up front: a case that exits CLEANLY is
    #     classified no matter how large its stderr happens to be — nothing
    #     was truncated, so there is nothing to call a cap-kill.
    #   - among the `rc != 0` cases, `rc == 153` (128+SIGXFSZ) is exact but
    #     platform-dependent (only known to hold on Linux/macOS, the two
    #     platforms this repo gates on); the size check (`size >= CAPPED_BYTES`)
    #     does not depend on the signal number at all, so a signalled child
    #     that reports something other than 153 on some other platform still
    #     gets caught by size. Requiring BOTH (AND) would silently mislabel
    #     that case as "never compiled" instead of "truncated" — exactly the
    #     conflation this round exists to remove, and worse than the
    #     alternative: an OR can at most mislabel a genuinely clean case (it
    #     can't, now that rc==0 is excluded first) or, in a case that FAILED
    #     for an unrelated reason with a stderr that happens to be large, tag
    #     a real compile failure as "cap-killed" too — an honest
    #     under-claim ("I may have missed data") — while an AND can hide a
    #     real cap-kill's partial findings behind "no MIR ever reached the
    #     emitter", a false claim that erases them from the work list. For a
    #     tool whose output is a work list, under-claiming coverage is
    #     acceptable; hiding a finding is not.
    if [ "$rc" != "0" ] && { [ "$rc" = "153" ] || [ "$size" -ge "$CAPPED_BYTES" ]; }; then
        n=$(grep -c "CELLGUARD raw->cell" "$out/$b.err" || true)
        mv "$out/$b.err" "$out/$b.err.cap_killed"
        echo "$b: cap-killed rc=$rc err_size=${size}B cap=${CAPPED_BYTES}B partial_violations=$n -- TRUNCATED mid-emission (SIGXFSZ), an UNDERCOUNT, excluded from every count" >> "$out/cap_killed.txt"
    elif [ "$rc" != "0" ]; then
        mv "$out/$b.err" "$out/$b.err.compile_fail"
        echo "$b: compile rc=$rc" >> "$out/compile_failures.txt"
    else
        n=$(grep -c "CELLGUARD raw->cell" "$out/$b.err" || true)
        [ "$n" = "0" ] || echo "$n $b"
    fi
done < "$out/cases.txt" | sort -rn | tee "$out/census.txt"

n_cases=$(wc -l < "$out/cases.txt" | tr -d ' ')
compile_fail=$(wc -l < "$out/compile_failures.txt" | tr -d ' ')
cap_killed=$(wc -l < "$out/cap_killed.txt" | tr -d ' ')
classified=$(( n_cases - compile_fail - cap_killed ))
# Runtime check on the invariant above, not just a comment: the three
# buckets must partition n_cases exactly. A future edit that reintroduces
# overlap (or a gap) fails the scan here instead of silently miscounting.
if [ "$((classified + compile_fail + cap_killed))" != "$n_cases" ]; then
    echo "INTERNAL ERROR: bucket counts do not partition attempted cases ($classified classified + $compile_fail compile-failed + $cap_killed cap-killed != $n_cases attempted) — the disjointness invariant broke"
    fail=1
fi
total=$(awk '{s+=$1} END {print s+0}' "$out/census.txt")
files=$(wc -l < "$out/census.txt" | tr -d ' ')
echo "violations: $total across $files case(s) (of $classified classified; $n_cases attempted)"
echo "pre-existing compile failures (unrelated rc!=0, e.g. rc=70): $compile_fail"
echo "  -> these $compile_fail cases have UNKNOWN cellguard status (no MIR ever reached the emitter) — excluded from every count above (violations, sites, boxed/opaque/raw/unchecked), NOT counted as clean"
echo "cap-killed (stderr hit the ${MAX_ERR_BYTES}B cap mid-emission, SIGXFSZ): $cap_killed"
if [ "$cap_killed" != "0" ]; then
    echo "  -> these $cap_killed cases have TRUNCATED cellguard status — killed WHILE emitting cellguard output, an UNDERCOUNT (not clean, not never-compiled); see $out/cap_killed.txt for each one's partial violation count"
fi

echo "── by sink kind (raw line occurrences, same caveat as above) ──"
cat "$out"/*.err 2>/dev/null | grep -o "raw->cell [a-z_]*" | sort | uniq -c | sort -rn

echo "── THE WORK LIST: distinct (fn,line) sites, ranked by number of DISTINCT CASES that reach them ──"
# A raw violation line is a call SITE (fn+line), not a case: one prelude body
# (usort, a monomorphized closure, ...) compiles into every case that pulls it
# in and logs a hit per case, so the per-case count above is dominated by
# "how much of the prelude did this case load", not by where the bug lives.
# This section deduplicates by (fn,line) across the whole corpus and ranks by
# how many distinct cases reach each site — that is what a later task should
# work from. Re-running the scan regenerates this from the fresh .err files.
#
# Caveat: fn=__main is each case's own top-level script body, not one shared
# function — its "line" is relative to that one file, so two different cases'
# __main hitting the same line number are almost certainly unrelated code
# that happen to share a line number, not the same site. Read __main rows in
# the ranked list with that in mind; every OTHER fn name is a real shared
# body and the case-count is a real fan-in count.
: > "$out/site_pairs.txt"
for f in "$out"/*.err; do
    b=$(basename "$f" .err)
    grep "CELLGUARD raw->cell" "$f" 2>/dev/null | awk -v case="$b" '{
        sink=""; fn=""; line="";
        for (i = 1; i <= NF; i++) {
            if ($i == "raw->cell") sink = $(i+1);
            if ($i ~ /^fn=/)       fn = substr($i, 4);
            if ($i ~ /^line=/)     line = substr($i, 6);
        }
        if (fn != "" && line != "") print fn "\t" line "\t" sink "\t" case
    }' | sort -u
done >> "$out/site_pairs.txt"

awk -F'\t' '{
    key = $1 "\t" $2;
    if (!(key in sinkof)) sinkof[key] = $3;
    ck = key "\t" $4;
    if (!(ck in seen)) { seen[ck] = 1; cnt[key]++ }
}
END { for (k in cnt) print cnt[k] "\t" sinkof[k] "\t" k }' "$out/site_pairs.txt" \
    | sort -t "$(printf '\t')" -k1,1rn -k2,2 -k3,3 -k4,4n > "$out/site_ranked.txt"
# Columns are count/sink/fn/line. Primary key is the case count (descending —
# that is the ranking); the rest (sink, then fn, then line numerically) are
# tie-breakers only, so that rows tied on count come out in a FIXED order
# instead of awk's unspecified hash-iteration order. This is load-bearing:
# the census re-runs after each producer fix in the next stage, and a
# reshuffling top-30 makes "did this fix reduce the census" hard to read.

site_total=$(wc -l < "$out/site_ranked.txt" | tr -d ' ')
echo "raw raw->cell lines: $total   distinct (fn,line) sites: $site_total"
echo "columns: cases-reaching-this-site  sink  fn  line — top 30 of $site_total, full list in $out/site_ranked.txt"
head -n 30 "$out/site_ranked.txt" | column -t -s "$(printf '\t')"

echo "── site histogram by sink kind (distinct sites, not occurrences) ──"
awk -F'\t' '{print $2}' "$out/site_ranked.txt" | sort | uniq -c | sort -rn

echo "── aggregate boxed/opaque/raw/unchecked coverage ──"
cat "$out"/*.err 2>/dev/null \
    | grep "CELLGUARD summary" \
    | grep -o 'boxed=[0-9]*\|opaque=[0-9]*\|raw=[0-9]*\|unchecked=[0-9]*\|violations=[0-9]*' \
    | awk -F= '{a[$1]+=$2} END {for (k in a) print k"="a[k]}' | sort

echo "── coverage caveats (NOT bugs, deliberately uncovered/unchecked) ──"
cat <<'EOF'
- store_dyn_prop is 100% unchecked: a dynamic-property store picks one of N
  slots through a runtime strcmp chain; no field says the slot type without a
  new lowering-time field.
- some StoreElement/StoreProperty shapes (cell-based, erased, union, bag) are
  narrower than the emitter's own box predicates and also land in unchecked.
- these channels are deliberately unmarked and correctly report raw:
  emitLoadLocal's globalBacked branch, emitPropertyAccess's delegate branches,
  emitMagicCall's property-protocol paths, emitGeneratorMethod's getReturn arm.
- the per-case census ranks prelude LOAD, not defect weight (a shared body
  like usort counts once per case that pulls it in); the distinct-(fn,line)
  ranking above is the work list. Within it, fn=__main rows conflate distinct
  cases' unrelated top-level code that happens to share a line number.
- the pre-existing compile failures reported above have UNKNOWN cellguard
  status, not clean status — they never reached the emitter, so every count
  in this script's output silently excludes them by absence of data.
- a cap-killed case (see the cap-killed count above) has TRUNCATED cellguard
  status, not clean and not unknown — it DID reach the emitter and was
  producing real cellguard output when the stderr cap killed it; its count
  is an undercount, not an absence of data. Every count in this script's
  output excludes it by construction (its .err is renamed off the *.err
  glob these sections read), not by luck.
EOF

echo "── positive: an array through a \$GLOBALS slot (expect a violation) ──"
cat > "$out/bad.php" <<'PHP'
<?php
$g = [1, 2, 3];
$GLOBALS['g'] = [4, 5, 6];
\var_dump($GLOBALS['g']);
PHP
(
    ulimit -f "$MAX_ERR_BLOCKS"
    exec php -d memory_limit=2048M tools/compile_user_mir.php "$out/bad.php" \
        > /dev/null 2> "$out/bad.err"
)
if grep -q "CELLGUARD raw->cell" "$out/bad.err"; then
    echo "ok: known-broken channel still caught"
else
    echo "FAIL: instrument went blind on the positive case"; fail=1
fi

[ "$fail" = "0" ] && echo "CELLGUARD SCAN OK" || { echo "CELLGUARD SCAN FAILED"; exit 1; }
