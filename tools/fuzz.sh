#!/usr/bin/env bash
#
# Fuzz parity harness: generate valid, deterministic PHP (tools/fuzz_gen.php),
# then diff the manticore-compiled binary's output against the PHP interpreter.
# A divergence is a REAL codegen bug; a compile failure on php-runnable source
# is an unsupported-construct gap. Catches regressions the 396-case corpus
# can't, across randomised expression trees.
#
#   tools/fuzz.sh [count] [start-seed]     (default: 300 programs from seed 1)
#
# Buckets mirror difftest.sh: MATCH / DIFF / COMPILE / PHP-SKIP. Every finding
# (the .php + both outputs) is written under .fuzz-findings/ for inspection.
# Non-zero exit when any DIFF or COMPILE-on-runnable is found, so it can gate.

set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MANTICORE="$ROOT/bin/manticore"
GEN="$ROOT/tools/fuzz_gen.php"
[[ -x "$MANTICORE" ]] || { echo "fatal: bin/manticore missing; run bin/build" >&2; exit 1; }
command -v php >/dev/null || { echo "fatal: php not found" >&2; exit 1; }

COUNT="${1:-300}"
START="${2:-1}"

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
FIND="$ROOT/.fuzz-findings"
rm -rf "$FIND"; mkdir -p "$FIND"

match=0 diff=0 compile=0 phpskip=0
declare -a DIFFS COMPILES

end=$((START + COUNT - 1))
for seed in $(seq "$START" "$end"); do
    prog="$WORK/p_$seed.php"
    php "$GEN" "$seed" > "$prog" 2>/dev/null || { phpskip=$((phpskip + 1)); continue; }

    # Reference: the PHP interpreter. A fatal/parse error means the generated
    # program tripped a php edge (shouldn't happen for a valid subset) — skip.
    ref="$(php -d error_reporting=0 -d display_errors=0 "$prog" 2>"$WORK/ref.err")"; rrc=$?
    if [[ $rrc -ne 0 && -z "$ref" ]] || grep -qiE 'Parse error|Fatal error|Uncaught' "$WORK/ref.err"; then
        phpskip=$((phpskip + 1)); continue
    fi

    if ! "$MANTICORE" compile "$prog" -o "$WORK/bin" >"$WORK/c.err" 2>&1; then
        compile=$((compile + 1)); COMPILES+=("$seed")
        cp "$prog" "$FIND/compile_$seed.php"; cp "$WORK/c.err" "$FIND/compile_$seed.err"
        continue
    fi
    got="$("$WORK/bin" 2>/dev/null)"

    if [[ "$got" == "$ref" ]]; then
        match=$((match + 1))
    else
        diff=$((diff + 1)); DIFFS+=("$seed")
        cp "$prog" "$FIND/diff_$seed.php"
        printf '%s' "$ref" > "$FIND/diff_${seed}.php.expected"
        printf '%s' "$got" > "$FIND/diff_${seed}.php.got"
    fi
done

echo "════════ fuzz vs PHP $(php -r 'echo PHP_VERSION;') — $COUNT programs (seed $START..$end) ════════"
echo "  MATCH:     $match"
echo "  DIFF:      $diff"
echo "  COMPILE:   $compile   (manticore failed to compile a php-runnable program)"
echo "  PHP-SKIP:  $phpskip   (generator/php edge — not counted)"

if [[ ${#DIFFS[@]} -gt 0 ]]; then
    echo "── output DIFFs (seeds; see .fuzz-findings/) ──"
    printf '  %s\n' "${DIFFS[@]}"
fi
if [[ ${#COMPILES[@]} -gt 0 ]]; then
    echo "── compile failures (seeds; see .fuzz-findings/) ──"
    printf '  %s\n' "${COMPILES[@]}"
fi

[[ $diff -eq 0 && $compile -eq 0 ]]
