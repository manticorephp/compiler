#!/usr/bin/env bash
#
# The analyzer's prelude index is cached between compiles (keyed by the prelude's
# content and the compiler binary) instead of re-parsing the prelude every time.
#
#   bash tests/analyze/prelude_cache.sh
#
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
MANT="$ROOT/bin/manticore"
[[ -x "$MANT" ]] || { echo "no bin/manticore — run bin/build first" >&2; exit 2; }

WORK="$ROOT/tests/analyze/.prelude-cache-work"
rm -rf "$WORK"; mkdir -p "$WORK"
trap 'chmod -R u+w "$WORK" 2>/dev/null; rm -rf "$WORK"' EXIT
export MANTICORE_OBJ_CACHE_DIR="$WORK/cache/obj"
fail=0
expect() { if eval "$2"; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi; }

# Calls a prelude function with too few arguments: the ArgCount rule reads the
# prelude signature from the index.
cat > "$WORK/f.php" <<'PHP'
<?php
function array_combine_twice(array $k): array { return array_combine($k); }
echo json_encode(array_flip(['a' => 1])), "\n";
PHP

# analyze <tag> [env...]: diagnostics to <tag>.out, trace to <tag>.trace
analyze() {
    local tag="$1"; shift
    env "$@" MANTICORE_PHASE_TRACE=1 "$MANT" analyze "$WORK/f.php" > "$WORK/$tag.out" 2> "$WORK/$tag.trace"
    grep -v '^stats:' "$WORK/$tag.trace" >> "$WORK/$tag.out"
}

analyze miss
expect "first run parses the prelude" 'grep -q "analyze: prelude files" "$WORK/miss.trace"'
expect "first run reports the prelude ArgCount" 'grep -q "too few arguments to array_combine()" "$WORK/miss.out"'
analyze hit
expect "second run reads the cached index" 'grep -q "analyze: prelude index (cached)" "$WORK/hit.trace"'
expect "cached diagnostics are byte-identical" 'cmp -s "$WORK/miss.out" "$WORK/hit.out"'

# A prelude edited in place (a dev tree reads prelude/ from source) is a miss,
# and the new signature is what the rule checks.
cp -R prelude "$WORK/prelude"
sed -i.bak 's/^function array_combine(array \$keys, array \$values): array/function array_combine(array $keys, array $values = []): array/' "$WORK/prelude/array_fns.php"
analyze edited MANTICORE_PRELUDE="$WORK/prelude"
expect "edited prelude misses the cache" 'grep -q "analyze: prelude files" "$WORK/edited.trace"'
expect "edited signature is the one checked" '! grep -q "too few arguments to array_combine()" "$WORK/edited.out"'

# A read-only cache still analyzes.
mkdir -p "$WORK/cache"; chmod -R a-w "$WORK/cache"
analyze ro MANTICORE_PRELUDE="$WORK/prelude"
expect "read-only cache still analyzes" '! grep -q "too few arguments to array_combine()" "$WORK/ro.out" && grep -q "analyze: prelude" "$WORK/ro.trace"'
chmod -R u+w "$WORK/cache"

# A user function named like a prelude one resolves exactly as with no cache.
cat > "$WORK/f.php" <<'PHP'
<?php
function array_flip(array $a, int $extra): array { return $a; }
echo json_encode(array_flip(['a' => 1])), "\n";
PHP
rm -rf "$WORK/cache"
analyze shadow_miss
analyze shadow_hit
expect "shadowed prelude name: cached == uncached" 'cmp -s "$WORK/shadow_miss.out" "$WORK/shadow_hit.out"'

[[ $fail -eq 0 ]] && echo "ok: analyzer prelude cache"
exit $fail
