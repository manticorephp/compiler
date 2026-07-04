#!/usr/bin/env bash
#
# Differential test: manticore-compiled output vs the PHP interpreter.
#
#   tools/difftest.sh [path ...]      (default: tests/aot/cases/*.php)
#
# For each PHP file: run it under `php`, compile+run it under manticore, and
# diff stdout. This finds REAL divergences (a manticore bug, or a stale
# expected/ file) and measures language/stdlib parity beyond the curated
# expected outputs. Cases that `php` itself cannot run (manticore-only
# features — FFI stubs, #[Struct], compile-error fixtures) are auto-skipped as
# "php-incompat", not counted as failures.
#
# Buckets: MATCH (same stdout), DIFF (manticore != php), COMPILE (manticore
# failed to compile), PHP-SKIP (php errored / can't run it). Only DIFF and
# COMPILE on a php-runnable file are real findings; they are listed at the end.

set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MANTICORE="$ROOT/bin/manticore"
[[ -x "$MANTICORE" ]] || { echo "fatal: bin/manticore missing; run bin/compile" >&2; exit 1; }
command -v php >/dev/null || { echo "fatal: php not found" >&2; exit 1; }

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

if [[ $# -gt 0 ]]; then
    FILES=("$@")
else
    FILES=(tests/aot/cases/*.php)
fi

# Known, understood divergences from PHP — excluded so the gate flags only
# NEW findings. Keep each with a one-line reason.
#   assoc_missing.php — typed-int assoc read of a missing key yields 0, not
#     null/"" (a typed-slot i64 can't carry null without boxing).
#   exception_backtrace.php — getTrace() is a V1 list of frame name strings, not
#     PHP's assoc frames; getTraceAsString keeps the path as passed (php realpaths
#     it, adding the macOS /private prefix). Both are intentional divergences.
is_known_divergence() { case "$1" in assoc_missing.php|exception_backtrace.php) return 0;; esac; return 1; }

match=0 diff=0 compile=0 phpskip=0
declare -a DIFFS COMPILES

for f in "${FILES[@]}"; do
    [[ -f "$f" ]] || continue
    name="$(basename "$f")"

    # Reference: the PHP interpreter. A fatal/parse error (rc!=0 with no
    # stdout, or a stderr Fatal) means the file isn't plain runnable PHP.
    ref="$(php -d error_reporting=0 -d display_errors=0 "$f" 2>"$WORK/ref.err")"; rrc=$?
    if [[ $rrc -ne 0 && -z "$ref" ]] || grep -qiE 'Parse error|Fatal error|Uncaught' "$WORK/ref.err"; then
        phpskip=$((phpskip + 1)); continue
    fi

    # Manticore: compile then run.
    if ! "$MANTICORE" compile "$f" -o "$WORK/bin" >"$WORK/c.err" 2>&1; then
        compile=$((compile + 1)); COMPILES+=("$name"); continue
    fi
    got="$("$WORK/bin" 2>/dev/null)"

    if [[ "$got" == "$ref" ]]; then
        match=$((match + 1))
    elif is_known_divergence "$name"; then
        match=$((match + 1))   # documented limitation, not a regression
    else
        diff=$((diff + 1)); DIFFS+=("$name")
    fi
done

echo "════════ difftest vs PHP $(php -r 'echo PHP_VERSION;') ════════"
echo "  MATCH:     $match"
echo "  DIFF:      $diff"
echo "  COMPILE:   $compile   (manticore failed to compile a php-runnable file)"
echo "  PHP-SKIP:  $phpskip   (not plain-runnable under php — manticore-only)"

if [[ ${#DIFFS[@]} -gt 0 ]]; then
    echo "── output DIFFs (manticore != php) ──"
    printf '  %s\n' "${DIFFS[@]}"
fi
if [[ ${#COMPILES[@]} -gt 0 ]]; then
    echo "── compile failures (php-runnable) ──"
    printf '  %s\n' "${COMPILES[@]}"
fi

# Non-zero exit if there is any real finding, so this can gate.
[[ $diff -eq 0 && $compile -eq 0 ]]
