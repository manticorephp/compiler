#!/usr/bin/env bash
# CLI golden test. behaviour.txt = what commands DO (captured before the CLI
# restructure; must not change). help.txt = generated usage text (review its diff).
#   bash tests/cli/run.sh            compare
#   bash tests/cli/run.sh --update   rewrite help.txt only
#   bash tests/cli/run.sh --capture  rewrite behaviour.txt (baseline only; never after the restructure)
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MC="${MC:-$ROOT/bin/manticore}"
HERE="$ROOT/tests/cli"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
cd "$WORK"  # relative paths: the IR embeds the source path
cat > "$WORK/f.php" <<'PHP'
<?php
function add(int $a, int $b): int { return $a + $b; }
echo add(2, 3), "\n";
PHP

run() { # label, then the command: exit code only
    local label="$1"; shift
    local rc
    "$@" >/dev/null 2>&1; rc=$?
    printf '== %s\nrc=%s\n' "$label" "$rc"
}
sum() { # label, then the command: checksum of stdout + rc
    local label="$1"; shift
    local s rc
    "$@" >"$WORK/o" 2>/dev/null; rc=$?
    s="$(cksum <"$WORK/o")"
    printf '== %s\n%s rc=%s\n' "$label" "$s" "$rc"
}

behaviour() {
    sum "version"            "$MC" version
    sum "dump-ast"           "$MC" dump-ast "f.php"
    sum "dump-mir"           "$MC" dump-mir "f.php"
    sum "dump-sig"           "$MC" dump-sig "f.php"
    sum "dump-llvm-mir"      "$MC" dump-llvm-mir "f.php"
    sum "dump-llvm"          "$MC" dump-llvm "f.php"
    sum "analyze json"       "$MC" analyze --json "f.php"
    run "dump-ast bogus flag"      "$MC" dump-ast --bogus "f.php"
    run "dump-ast missing file"    "$MC" dump-ast "nope.php"
    run "dump-ast with compile-only flag" "$MC" dump-ast --analyze "f.php"
    run "compile bad -O"           "$MC" compile "f.php" -o "x" -O9
    run "compile bad --memory"     "$MC" compile "f.php" -o "x" --memory bogus
    run "split-ir no args"         "$MC" split-ir
    run "build missing manifest"   "$MC" build "nope.json"
    run "unknown command"          "$MC" frobnicate
    "$MC" compile "f.php" -o "prog" >/dev/null 2>&1
    if [ -x ./prog ]; then out="$(./prog 2>&1)"; else out="no binary"; fi
    printf '== compile+run\n%s\n' "$out"
}

helps() {
    for c in "" compile build analyze dump-llvm dump-ast dump-mir dump-llvm-mir dump-sig split-ir version; do
        printf '== help %s\n' "${c:-<root>}"
        if [ -z "$c" ]; then "$MC" help 2>&1; else "$MC" "$c" --help 2>&1; fi
    done
}

fail=0
if [ "${1:-}" = "--update" ]; then
    helps > "$HERE/help.txt"; echo "help.txt updated"; exit 0
fi
if [ "${1:-}" = "--capture" ]; then
    behaviour > "$HERE/behaviour.txt"; echo "behaviour.txt captured"; exit 0
fi
behaviour > "$WORK/behaviour.got"
diff -u "$HERE/behaviour.txt" "$WORK/behaviour.got" || fail=1
if [ -f "$HERE/help.txt" ]; then
    helps > "$WORK/help.got"
    diff -u "$HERE/help.txt" "$WORK/help.got" || fail=1
fi
[ "$fail" = 0 ] && echo "=== cli golden: ok ===" || { echo "=== cli golden: FAILED ==="; exit 1; }
