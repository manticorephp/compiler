#!/usr/bin/env bash
# Programs php rejects at compile time (class-declaration contracts) must not
# compile here: each <name>.php must fail with a non-zero exit and its stderr
# must contain the line in <name>.err (php's fatal message, location stripped).
#   bash tests/aot/reject/run.sh [-k substr]
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
MC="${MC:-$ROOT/bin/manticore}"
HERE="$ROOT/tests/aot/reject"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
filter=""; [ "${1:-}" = "-k" ] && filter="$2"
pass=0; fail=0
for src in "$HERE"/*.php; do
    name="$(basename "$src" .php)"
    case "$name" in *"$filter"*) ;; *) continue;; esac
    "$MC" compile "$src" -o "$WORK/$name" >"$WORK/$name.log" 2>&1; rc=$?
    if [ $rc -ne 0 ] && [ ! -e "$WORK/$name" ] && grep -qF -- "$(cat "${src%.php}.err")" "$WORK/$name.log"; then
        pass=$((pass + 1))
    else
        fail=$((fail + 1)); echo "FAIL $name (rc=$rc): $(head -c 300 "$WORK/$name.log")"
    fi
done
echo "reject: passed $pass failed $fail"
[ $fail -eq 0 ]
