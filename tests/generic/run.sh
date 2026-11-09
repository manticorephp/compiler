#!/usr/bin/env bash
#
# Shared-generics identity gate.
#
# Compiles every tests/generic/cases/*.php with --keep-ir, checks each binary
# prints what php printed (cases/<name>.out), then measures how many bytes of
# the shareable prelude/runtime bodies are byte-identical across the programs
# (tools/generic_identity.php). Fails below the floor in tests/generic/threshold.
#
#   bash tests/generic/run.sh                 check against tests/generic/threshold
#   bash tests/generic/run.sh --threshold N   check against N instead
#   bash tests/generic/run.sh --diffs N       print up to N differing bodies (default 40)
#
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
MANT="bin/manticore"
[[ -x "$MANT" ]] || { echo "no $MANT — run bin/build first" >&2; exit 2; }

THRESHOLD="$(cat tests/generic/threshold)"
DIFFS=40
while [[ $# -gt 0 ]]; do
    case "$1" in
        --threshold) THRESHOLD="$2"; shift 2 ;;
        --diffs) DIFFS="$2"; shift 2 ;;
        *) echo "unknown option $1" >&2; exit 2 ;;
    esac
done

WORK="tests/generic/.work"
rm -rf "$WORK"
mkdir -p "$WORK"
trap 'rm -rf "$WORK"' EXIT

fail=0
lls=()
for src in tests/generic/cases/*.php; do
    name="$(basename "$src" .php)"
    dir="$WORK/$name"
    mkdir -p "$dir"
    if ! (cd "$dir" && "$ROOT/$MANT" compile -O2 --keep-ir "$ROOT/$src" -o bin > compile.log 2>&1); then
        echo "FAIL $name: compile"; tail -5 "$dir/compile.log"; fail=1; continue
    fi
    if ! "$dir/bin" > "$dir/out" 2>&1 || ! cmp -s "$dir/out" "tests/generic/cases/$name.out"; then
        echo "FAIL $name: output differs from php"; diff "tests/generic/cases/$name.out" "$dir/out" | head -6; fail=1
    fi
    lls+=("$dir/bin.dbg.ll")
    # The executable's own module needs no per-program module token (it takes
    # the fixed `app`): only libraries can collide with it, and they keep theirs.
    tok="$(grep -cE '@[A-Za-z_.$0-9]+_m[0-9a-f]{12,16}\b' "$dir/bin.dbg.ll" || true)"
    if [[ "$tok" != "0" ]]; then
        echo "FAIL $name: $tok lines name module-tokened helpers in an app module"
        grep -oE '@[A-Za-z_.$0-9]+_m[0-9a-f]{12,16}\b' "$dir/bin.dbg.ll" | sort -u | head -3
        fail=1
    fi
done
[[ $fail -eq 0 ]] || exit 1

report="$(php tools/generic_identity.php --diffs "$DIFFS" "${lls[@]}")"
echo "$report"
pct="$(echo "$report" | sed -nE 's/^generic-identical: ([0-9.]+)%.*/\1/p')"
hits="$(echo "$report" | sed -nE 's/^variant-hits: ([0-9.]+)%.*/\1/p')"
HIT_THRESHOLD="$(cat tests/generic/hit_threshold)"
rc=0
if ! php -r "exit((float)'$pct' >= (float)'$THRESHOLD' ? 0 : 1);"; then
    echo "FAIL generic-identical $pct% < threshold $THRESHOLD%"; rc=1
fi
if ! php -r "exit((float)'$hits' >= (float)'$HIT_THRESHOLD' ? 0 : 1);"; then
    echo "FAIL variant-hits $hits% < threshold $HIT_THRESHOLD%"; rc=1
fi
[[ $rc -eq 0 ]] && echo "ok: generic-identical $pct% >= $THRESHOLD%, variant-hits $hits% >= $HIT_THRESHOLD%"
exit $rc
