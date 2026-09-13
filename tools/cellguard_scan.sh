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
#
# SITE KEY: (sink, fn, ord). `ord` is the per-function ordinal of the cell
# sink (the N-th cell sink checked in that function's emission — emitted by
# EmitLlvmCellGuard::checkCellSink as `ord=N`). A prelude function's `line`
# is MODULE-dependent (prelude assembly is demand-driven, so one source node
# lands at a different absolute line per case) and is therefore NOT a site
# identity; it is kept in the output for humans and dropped from the key.
# fn=__main is each case's own top-level body, not one shared function, so a
# __main row is keyed as `__main@<case basename>` (the scan knows the case
# from the .err filename) — two cases' __main sinks never collapse into one.
#
# --ratchet: after the census, take the (sink, fn, ord) site set and diff it
# against the committed baseline (tools/cellguard_baseline.txt, see
# --update-baseline). Any site in current-but-not-baseline is NEW and fails
# the scan (exit 1) with every new site listed. A site in baseline-but-not-
# current is CLOSED and is reported as progress only — it never fails the
# scan by itself. A subset run's "closed" count is an artifact of scanning
# fewer cases than the baseline was built from, not real progress; only a
# full-corpus run's "closed" list is meaningful to act on.
#
# --update-baseline: rewrite tools/cellguard_baseline.txt from the current
# run's corrected site set. Never automatic, never implied by --ratchet.
# Refuses (exit 1, baseline untouched) whenever the current site SET contains
# any site not already in the existing baseline — a "new" set, computed the
# exact same way --ratchet computes it (a same-count swap, N sites closing
# while N different ones open, is still a refusal: comparing totals alone
# would miss it) — unless --force accompanies it. A pure drop (nothing new,
# some sites closed) always updates freely; that direction can only ever be
# progress, never a silent regression. --update-baseline HARD-REFUSES outright
# (no --force override — none exists for this one) when CELLGUARD_SUBSET is
# set: a subset of the corpus can never be a valid corpus-wide baseline, and
# a leftover exported CELLGUARD_SUBSET from an earlier smoke test is exactly
# the operator error this guards against.
#
# Default mode (no flags) is unchanged: same census, same non-gating exit 0
# (bar the pre-existing invariant/positive-case checks). It reads the
# baseline only to report its size in one informational line — it never
# writes it and never gates on it.
#
# --ratchet and --update-baseline are mutually exclusive: both on one command
# line is a hard error before anything runs, not "the later flag wins".
#
# LC_ALL=C is pinned on every sort/comm that touches the site set or the
# baseline (including the sort that produces the committed file's own order)
# — this repo gates on both macOS and Linux, and an unpinned collation can
# make `comm` misreport new/closed with no error at all if the baseline was
# written under a different locale than it's diffed under.
set -u
cd "$(dirname "$0")/.."

export MC_SRC="$PWD/src"
export MC_SIG="$PWD/lib/manticore_stdlib.o.sig"
export MANTICORE_PRELUDE="$PWD/prelude"
export MANTICORE_CELLGUARD=1

CELLGUARD_SUBSET="${CELLGUARD_SUBSET:-0}"

# --- ratchet flag parsing -----------------------------------------------
RATCHET_FLAG=0
UPDATE_FLAG=0
FORCE=0
for arg in "$@"; do
    case "$arg" in
        --ratchet)         RATCHET_FLAG=1 ;;
        --update-baseline) UPDATE_FLAG=1 ;;
        --force)           FORCE=1 ;;
        *)
            echo "unknown flag: $arg (expected --ratchet, --update-baseline, --force)" >&2
            exit 2
            ;;
    esac
done
if [ "$RATCHET_FLAG" = "1" ] && [ "$UPDATE_FLAG" = "1" ]; then
    echo "--ratchet and --update-baseline are mutually exclusive -- pick one (a mistyped or" >&2
    echo "  copy-pasted command that meant to only CHECK must never silently WRITE)" >&2
    exit 2
fi
MODE="default"
[ "$RATCHET_FLAG" = "1" ] && MODE="ratchet"
[ "$UPDATE_FLAG" = "1" ] && MODE="update-baseline"
if [ "$FORCE" = "1" ] && [ "$MODE" != "update-baseline" ]; then
    echo "--force is only meaningful with --update-baseline" >&2
    exit 2
fi
BASELINE_FILE="tools/cellguard_baseline.txt"

# CRITICAL: a subset scan can never be a valid corpus-wide baseline. Refuse
# outright, before running anything -- there is no --force for this one.
if [ "$MODE" = "update-baseline" ] && [ "$CELLGUARD_SUBSET" != "0" ]; then
    echo "REFUSED: --update-baseline cannot run with CELLGUARD_SUBSET=$CELLGUARD_SUBSET set." >&2
    echo "  A subset scan can never be a valid corpus-wide baseline -- this would silently" >&2
    echo "  overwrite the real baseline with a fraction of it. No --force override exists" >&2
    echo "  for this refusal: unset CELLGUARD_SUBSET, or run a full-corpus scan." >&2
    exit 1
fi

# Site count for a baseline-shaped file, and (as a side effect) the LC_ALL=C
# sort -u'd copy of it at $out/baseline_sorted.txt. ONE place both the
# default-mode banner and the --ratchet/--update-baseline paths get this
# number from, so they can never disagree (a raw `wc -l` would over-count a
# stray duplicate or blank line; a locale-unpinned sort could reorder the
# committed file differently on another machine). Missing file -> empty
# baseline_sorted.txt, count 0.
prepare_baseline_sorted() {
    if [ -f "$1" ]; then
        LC_ALL=C sort -u "$1" > "$out/baseline_sorted.txt"
    else
        : > "$out/baseline_sorted.txt"
    fi
    wc -l < "$out/baseline_sorted.txt" | tr -d ' '
}

# The one new/closed computation both --ratchet and --update-baseline call --
# factored out so they cannot drift onto two different definitions of "new"
# (Critical 2: a total-count comparison alone misses a same-count swap, N
# sites closing while N different ones open). Requires $out/site_correct.txt
# (current) and $out/baseline_sorted.txt (existing baseline) to already exist,
# both already LC_ALL=C sort -u'd. Sets new_count/closed_count and writes
# $out/ratchet_new.txt / $out/ratchet_closed.txt.
compute_ratchet_diff() {
    LC_ALL=C comm -23 "$out/site_correct.txt" "$out/baseline_sorted.txt" > "$out/ratchet_new.txt"
    LC_ALL=C comm -13 "$out/site_correct.txt" "$out/baseline_sorted.txt" > "$out/ratchet_closed.txt"
    new_count=$(wc -l < "$out/ratchet_new.txt" | tr -d ' ')
    closed_count=$(wc -l < "$out/ratchet_closed.txt" | tr -d ' ')
}

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

echo "── THE WORK LIST: distinct (sink,fn,ord) sites, ranked by number of DISTINCT CASES that reach them ──"
# A raw violation line is a SITE, not a case: one prelude body (usort, a
# monomorphized closure, ...) compiles into every case that pulls it in and
# logs a hit per case, so the per-case count above is dominated by "how much
# of the prelude did this case load", not by where the bug lives. This
# section deduplicates by the (sink, fn, ord) site key (header comment) across
# the whole corpus and ranks by how many distinct cases reach each site — that
# is the work list. `line` is carried along as a REPRESENTATIVE (the smallest
# seen) for humans only; the same prelude site legitimately shows different
# lines in different cases. fn=__main rows are keyed `__main@<case>`, so their
# case-count is always 1 by construction; every other fn is a real shared
# body and its case-count is a real fan-in count.
#
# site_pairs.txt columns: fn \t ord \t sink \t case \t line.
: > "$out/site_pairs.txt"
for f in "$out"/*.err; do
    b=$(basename "$f" .err)
    grep "CELLGUARD raw->cell" "$f" 2>/dev/null | awk -v case="$b" '{
        sink=""; fn=""; ord=""; line="";
        for (i = 1; i <= NF; i++) {
            if ($i == "raw->cell") sink = $(i+1);
            if ($i ~ /^fn=/)       fn = substr($i, 4);
            if ($i ~ /^ord=/)      ord = substr($i, 5);
            if ($i ~ /^line=/)     line = substr($i, 6);
        }
        if (fn == "__main") fn = "__main@" case;
        if (fn != "" && ord != "") print fn "\t" ord "\t" sink "\t" case "\t" line
    }' | sort -u
done >> "$out/site_pairs.txt"

awk -F'\t' '{
    key = $3 "\t" $1 "\t" $2;
    if (!(key in minline) || $5 + 0 < minline[key] + 0) minline[key] = $5;
    ck = key "\t" $4;
    if (!(ck in seen)) { seen[ck] = 1; cnt[key]++ }
}
END { for (k in cnt) print cnt[k] "\t" k "\t" minline[k] }' "$out/site_pairs.txt" \
    | sort -t "$(printf '\t')" -k1,1rn -k2,2 -k3,3 -k4,4n > "$out/site_ranked.txt"
# Columns are count/sink/fn/ord/line. Primary key is the case count
# (descending — that is the ranking); the rest (sink, then fn, then ord
# numerically) are tie-breakers only, so that rows tied on count come out in
# a FIXED order instead of awk's unspecified hash-iteration order. This is
# load-bearing: the census re-runs after each producer fix, and a reshuffling
# top-30 makes "did this fix reduce the census" hard to read.

site_total=$(wc -l < "$out/site_ranked.txt" | tr -d ' ')
echo "raw raw->cell lines: $total   distinct (sink,fn,ord) sites: $site_total"
echo "columns: cases-reaching-this-site  sink  fn  ord  line(representative) — top 30 of $site_total, full list in $out/site_ranked.txt"
head -n 30 "$out/site_ranked.txt" | column -t -s "$(printf '\t')"

echo "── site histogram by sink kind (distinct sites, not occurrences) ──"
awk -F'\t' '{print $2}' "$out/site_ranked.txt" | sort | uniq -c | sort -rn

echo "── aggregate boxed/opaque/probed/raw/unchecked coverage ──"
cat "$out"/*.err 2>/dev/null \
    | grep "CELLGUARD summary" \
    | grep -o 'boxed=[0-9]*\|opaque=[0-9]*\|probed=[0-9]*\|raw=[0-9]*\|unchecked=[0-9]*\|violations=[0-9]*' \
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
  like usort counts once per case that pulls it in); the distinct-(sink,fn,ord)
  ranking above is the work list. `line` there is a representative only — a
  prelude site's line is module-dependent and is not part of the key.
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

if [ -f "$BASELINE_FILE" ]; then
    baseline_size_now=$(prepare_baseline_sorted "$BASELINE_FILE")
else
    baseline_size_now="no baseline yet"
fi
echo "── ratchet: $BASELINE_FILE ($baseline_size_now known sites, keyed on the (sink, fn, ord) triple; fn=__main keyed __main@<case>) ──"
echo "  this run (default mode) does not check it. Run with --ratchet to fail on any"
echo "  NEW site vs the baseline (closed sites are reported as progress, never fatal"
echo "  by themselves); run with --update-baseline (add --force if any site is new) to"
echo "  rewrite it after closing sites. --update-baseline refuses under CELLGUARD_SUBSET."

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

# --- site set, computed for --ratchet / --update-baseline only (never for
# default mode — see the header comment). Keyed sink\tfn\tord, straight off
# site_pairs.txt (fn\tord\tsink\tcase\tline), sorted+deduped. `line` is
# deliberately NOT in it (module-dependent, header comment).
if [ "$MODE" = "ratchet" ] || [ "$MODE" = "update-baseline" ]; then
    LC_ALL=C awk -F'\t' '{ print $3 "\t" $1 "\t" $2 }' "$out/site_pairs.txt" | LC_ALL=C sort -u > "$out/site_correct.txt"
    current_count=$(wc -l < "$out/site_correct.txt" | tr -d ' ')
fi

if [ "$MODE" = "ratchet" ]; then
    echo "── ratchet ──"
    if [ ! -f "$BASELINE_FILE" ]; then
        echo "RATCHET FAILED: no baseline at $BASELINE_FILE — run --update-baseline first"
        fail=1
    else
        baseline_count=$(prepare_baseline_sorted "$BASELINE_FILE")
        compute_ratchet_diff
        echo "baseline: $baseline_count site(s)   current: $current_count site(s)"
        if [ "$CELLGUARD_SUBSET" != "0" ]; then
            echo "NOTE: this is a SUBSET run ($CELLGUARD_SUBSET of $corpus_total cases) — a large"
            echo "  'closed' count below is an artifact of scanning fewer cases than the baseline"
            echo "  was built from, not real progress. Only trust 'closed' from a full-corpus run."
        fi
        echo "new sites (in current, not in baseline): $new_count"
        [ "$new_count" != "0" ] && sed 's/^/  NEW: /' "$out/ratchet_new.txt"
        echo "closed sites (in baseline, not in current): $closed_count"
        [ "$closed_count" != "0" ] && sed 's/^/  CLOSED: /' "$out/ratchet_closed.txt"
        if [ "$new_count" != "0" ]; then
            echo "RATCHET FAILED: $new_count new cellguard site(s) not in the baseline"
            fail=1
        elif [ "$closed_count" != "0" ]; then
            echo "RATCHET OK — progress: $closed_count site(s) closed since the baseline."
            echo "  Run: bash tools/cellguard_scan.sh --update-baseline"
        else
            echo "RATCHET OK — no change vs baseline"
        fi
    fi

    # --- self-test: a synthetic site NOT in the baseline must be caught.
    # Run OUTSIDE the corpus loop (same reason as the $GLOBALS positive case
    # above: its .err must never join the *.err glob the earlier aggregate/
    # ranking sections read) and diffed on a COPY of the real site set, never
    # the real one — this proves the comm-based detection itself works
    # without letting a synthetic fixture contaminate the real baseline
    # decision above. A named user function is used (not top-level code,
    # which would key on fn=__main and could coincidentally collide with a
    # real case's own __main line) so its fn name is guaranteed absent from
    # any real corpus case and therefore from the baseline.
    echo "── ratchet self-test: a synthetic known-new site must be caught ──"
    cat > "$out/selftest_newsite.php" <<'PHP'
<?php
function cellguard_selftest_new_site_9c31() {
    $g = [1, 2, 3];
    $GLOBALS['cellguard_selftest_new_site_var'] = [4, 5, 6];
    \var_dump($GLOBALS['cellguard_selftest_new_site_var']);
}
cellguard_selftest_new_site_9c31();
PHP
    (
        ulimit -f "$MAX_ERR_BLOCKS"
        exec php -d memory_limit=2048M tools/compile_user_mir.php "$out/selftest_newsite.php" \
            > /dev/null 2> "$out/selftest_newsite.err"
    )
    synth_site=$(grep "CELLGUARD raw->cell" "$out/selftest_newsite.err" | awk '{
        sink=""; fn=""; ord="";
        for (i = 1; i <= NF; i++) {
            if ($i == "raw->cell") sink = $(i+1);
            if ($i ~ /^fn=/)       fn = substr($i, 4);
            if ($i ~ /^ord=/)      ord = substr($i, 5);
        }
        if (fn != "" && ord != "") print sink "\t" fn "\t" ord
    }' | head -n 1)
    if [ -z "$synth_site" ]; then
        echo "RATCHET SELF-TEST FAILED: synthetic fixture produced no CELLGUARD violation at all -- instrument regression, not a ratchet bug"
        fail=1
    elif [ ! -f "$BASELINE_FILE" ]; then
        echo "RATCHET SELF-TEST SKIPPED: no baseline at $BASELINE_FILE to test against"
    else
        # $out/baseline_sorted.txt was already prepared by the main ratchet
        # block above (the "elif" arm only runs here once we know that block
        # took its `if [ -f "$BASELINE_FILE" ]` branch) -- reuse it rather
        # than re-sorting, so there is exactly one prepared copy in play.
        { cat "$out/site_correct.txt"; printf '%s\n' "$synth_site"; } | LC_ALL=C sort -u > "$out/site_correct_plus_synth.txt"
        synth_new_hit=$(LC_ALL=C comm -23 "$out/site_correct_plus_synth.txt" "$out/baseline_sorted.txt" | grep -F -x "$synth_site" || true)
        if [ -n "$synth_new_hit" ]; then
            echo "ok: synthetic site '$synth_site' correctly flagged NEW by the ratchet diff"
        else
            echo "RATCHET SELF-TEST FAILED: synthetic site '$synth_site' was NOT flagged as new -- either it collided with a real baseline entry (name clash) or the detection logic is broken"
            fail=1
        fi
    fi
fi

if [ "$MODE" = "update-baseline" ]; then
    echo "── update-baseline ──"
    if [ ! -f "$BASELINE_FILE" ]; then
        # First-time bootstrap: there is no prior baseline to regress against,
        # so the growth refusal below does not apply -- it exists to catch a
        # REGRESSION against a real baseline, and "0 known sites" is not one.
        cp "$out/site_correct.txt" "$BASELINE_FILE"
        echo "baseline created: $current_count site(s) ($BASELINE_FILE) -- first-time bootstrap, no prior baseline to compare against"
    else
        baseline_count=$(prepare_baseline_sorted "$BASELINE_FILE")
        # Same new/closed computation --ratchet uses (Critical 2: comparing
        # totals alone misses a same-count swap -- N sites closing while N
        # DIFFERENT ones open leaves the count unchanged but is still a real
        # regression). Refuse whenever ANY new site exists, regardless of the
        # net count; --force overrides that refusal and nothing else.
        compute_ratchet_diff
        echo "baseline: $baseline_count site(s)   current: $current_count site(s)"
        echo "new sites (in current, not in baseline): $new_count"
        [ "$new_count" != "0" ] && sed 's/^/  NEW: /' "$out/ratchet_new.txt"
        echo "closed sites (in baseline, not in current): $closed_count"
        [ "$closed_count" != "0" ] && sed 's/^/  CLOSED: /' "$out/ratchet_closed.txt"
        if [ "$new_count" != "0" ] && [ "$FORCE" != "1" ]; then
            echo "REFUSED: $new_count new cellguard site(s) not in the existing baseline (listed above) --"
            echo "  a regression cannot be silently accepted, regardless of the net site count (a"
            echo "  same-count swap must refuse too). Re-run with --force only after confirming"
            echo "  every site listed above under NEW is expected, never to wave away a real"
            echo "  new violation."
            fail=1
        else
            if [ "$new_count" != "0" ]; then
                echo "--force: accepting $new_count new site(s) into the baseline (listed above)."
            fi
            cp "$out/site_correct.txt" "$BASELINE_FILE"
            echo "baseline updated: $baseline_count -> $current_count site(s) ($BASELINE_FILE)"
        fi
    fi
fi

[ "$fail" = "0" ] && echo "CELLGUARD SCAN OK" || { echo "CELLGUARD SCAN FAILED"; exit 1; }
