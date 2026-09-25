#!/usr/bin/env bash
# Runs tools/ownflow_dump.php over every tests/flow/<name>.php (arguments from
# its `// ownflow-args:` line) and diffs against tests/flow/<name>.expected.
# --bless rewrites the .expected files. Exit 1 on any diff.
set -u
cd "$(dirname "$0")/.."
bless=0
[ "${1:-}" = "--bless" ] && bless=1
fail=0
for f in tests/flow/*.php; do
    name=${f%.php}
    args=$(sed -n 's|^// ownflow-args: ||p' "$f" | head -1)
    if [ -z "$args" ]; then echo "FAIL $f: no // ownflow-args: line"; fail=1; continue; fi
    # shellcheck disable=SC2086
    out=$(php -d xdebug.mode=off -d memory_limit=2048M tools/ownflow_dump.php "$f" $args 2>&1 | head -c 200000)
    if [ $bless -eq 1 ]; then
        printf '%s\n' "$out" > "$name.expected"
        echo "BLESS $name.expected"
        continue
    fi
    if [ ! -f "$name.expected" ]; then echo "FAIL $f: no $name.expected"; fail=1; continue; fi
    if d=$(diff <(printf '%s\n' "$out") "$name.expected"); then
        echo "PASS $f"
    else
        echo "FAIL $f"
        printf '%s\n' "$d" | head -40
        fail=1
    fi
done
exit $fail
