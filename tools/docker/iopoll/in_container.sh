#!/usr/bin/env bash
# Runs INSIDE the php:8.6 container. Cold-seeds Manticore on Linux, then compiles
# each Io\Poll case and diffs native output vs php 8.6 (the oracle). The repo is
# bind-mounted read-only at /repo; the build writes into src/, so copy first.
set -uo pipefail

cp -a /repo /build/src
cd /build/src || exit 2
# Kill any macOS Mach-O artifacts that rode in on the mount — a stale .o would
# fake a pass / break the Linux link.
rm -rf bin/manticore lib tests/aot/tmp

echo "== bin/compile (Zend cold seed → native, on Linux) =="
# ⚠ Pre-existing (not Io\Poll): the seed aborts here with glibc `free(): invalid
# pointer` building stdlib.o — a genuine invalid-free that macOS's allocator
# tolerates but glibc's always-on integrity check rejects (MALLOC_CHECK_ can't
# suppress it). Fix the Linux runtime bug to unblock this harness.
bash bin/compile > /build/compile.log 2>&1
rc=$?
if [ "$rc" -ne 0 ]; then
    echo "SEED-BUILD FAILED rc=$rc"
    tail -60 /build/compile.log
    exit 1
fi
echo "seed ok"

php -r 'echo "oracle: php ".PHP_VERSION."\n";'

fail=0
shopt -s nullglob
for case in tools/docker/iopoll/cases/*.php; do
    name="$(basename "$case" .php)"
    ref="$(php "$case" 2>/dev/null)"
    if ! ./bin/manticore compile "$case" -o /tmp/b > /tmp/c.log 2>&1; then
        echo "COMPILE  $name"
        sed 's/^/    /' /tmp/c.log
        fail=1
        continue
    fi
    got="$(/tmp/b 2>/dev/null)"
    if [ "$got" == "$ref" ]; then
        echo "MATCH    $name"
    else
        echo "DIFF     $name"
        diff <(printf '%s\n' "$ref") <(printf '%s\n' "$got") | sed 's/^/    /'
        fail=1
    fi
done

if [ "$fail" -eq 0 ]; then echo "ALL MATCH"; else echo "FAILURES"; fi
exit "$fail"
