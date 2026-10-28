#!/usr/bin/env bash
# Runs every tests/semantic/*.php natively: bin/manticore (or $MANTICORE_BIN) compiles
# src/ plus all tests into ONE binary. Each test script is moved into its own namespace
# (its top-level code runs at start-up, in file order) and its trailing `exit($fail)`
# folds into the combined exit code. `-k a,b` keeps only tests whose name contains a or b.
# Not for Zend: a test that passes here proves the NATIVE analyzer.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
BIN="${MANTICORE_BIN:-$ROOT/bin/manticore}"
K=""
if [ "${1:-}" = "-k" ]; then K="${2:-}"; fi

W="$(mktemp -d "${TMPDIR:-/tmp}/semtest.XXXXXX")"
[ -n "${SEM_KEEP:-}" ] || trap 'rm -rf "$W"' EXIT
mkdir -p "$W/src/SemTest"
# src minus main.php: its top-level call would run the compiler driver.
(cd "$ROOT/src" && find . -name '*.php' ! -path './main.php' | while read -r f; do
    mkdir -p "$W/src/$(dirname "$f")"; cp "$f" "$W/src/$f"; done)

names=()
for f in "$HERE"/*.php; do
    n="$(basename "$f" .php)"
    [ "$n" = bootstrap ] && continue
    if [ -n "$K" ]; then keep=0; for k in ${K//,/ }; do [[ "$n" == *"$k"* ]] && keep=1; done; [ $keep = 1 ] || continue; fi
    names+=("$n")
    {
        echo "<?php"
        echo "namespace SemTest\\$n;"
        echo "echo \"== $n.php\\n\";"
        sed -e '1{/^<?php/d;}' \
            -e "s|dirname(__DIR__, 2)|'$ROOT'|g" -e "s|dirname(__DIR__) \. '/analyze|'$ROOT/tests/analyze|g" -e "s|__DIR__ \. '/../analyze|'$ROOT/tests/analyze|g" \
            -e "/^require __DIR__ \\. '\\/bootstrap\\.php';/d" \
            -e "s/^exit(\\\$fail);\$/\\\$GLOBALS['sem_rc'] = (\\\$GLOBALS['sem_rc'] ?? 0) | (int)\\\$fail;/" "$f"
    } > "$W/src/SemTest/$n.php"
done
[ ${#names[@]} -gt 0 ] || { echo "no tests match '$K'"; exit 2; }
{
    echo "<?php"
    for n in "${names[@]}"; do echo "require __DIR__ . '/$n.php';"; done
    echo "exit((int)(\$GLOBALS['sem_rc'] ?? 0));"
} > "$W/src/SemTest/zz_end.php"
printf '{"applications":[{"name":"semtest","src":"src","entry":"src/SemTest/zz_end.php","output":"bin/semtest","stdlib":false}]}\n' > "$W/manticore.json"

(cd "$W" && "$BIN" build 2>&1 | tail -20) || exit 1
[ -x "$W/bin/semtest" ] || { echo "semantic tests: build failed"; exit 1; }
"$W/bin/semtest"
