#!/usr/bin/env bash
# Drift guard: every name literal emitBuiltinDispatch matches (`$name === 'x'`)
# must be listed in UndefinedFunctionScan::BUILTIN_NAMES. Recognised forms: the
# `$name === '<x>'` / `"<x>"` equality (also inside `||` chains and `&&` guards).
# `count($args)` style guards and the `__mir_`/`__mc_` prefix routes need no entry.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/src/Compile/Mir/Passes/EmitLlvmBuiltins.php"
LIST="$ROOT/src/Compile/Mir/UndefinedFunctionScan.php"
start=$(grep -n 'private function emitBuiltinDispatch' "$SRC" | head -1 | cut -d: -f1)
[ -n "$start" ] || { echo "emitBuiltinDispatch not found"; exit 2; }
want="$(tail -n +"$start" "$SRC" | awk 'NR>1 && /^    }$/ {exit} {print}' \
  | grep -oE "\\\$name === (\"[^\"]+\"|'[^']+')" | sed -E "s/^\\\$name === //; s/^\"(.*)\"\$/'\1'/" | tr 'A-Z' 'a-z' | sort -u)"
have="$(awk '/const BUILTIN_NAMES/ {on=1; next} on && /^    \];/ {exit} on {gsub(/^ +| *,$/, ""); print}' "$LIST" | tr 'A-Z' 'a-z' | sort -u)"
missing="$(comm -23 <(printf '%s\n' "$want") <(printf '%s\n' "$have"))"
if [ -n "$missing" ]; then echo "missing from BUILTIN_NAMES:"; printf '%s\n' "$missing"; exit 1; fi
echo ok
