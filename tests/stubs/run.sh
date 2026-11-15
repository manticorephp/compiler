#!/usr/bin/env bash
# `manticore stubs` golden: tests/stubs/input/ (every construct a stub keeps or drops) ->
# expected/merged.php; one file -> expected/b_namespace.php; --keep-lines keeps declarations on
# their lines; --library has the prelude and stdlib sections. With php on PATH the merged stubs
# also pass `php -l` and load (classes, enum, functions exist).
#   bash tests/stubs/run.sh [--update]
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MC="${MC:-$ROOT/bin/manticore}"
HERE="$ROOT/tests/stubs"
W="$(mktemp -d)"; trap 'rm -rf "$W"' EXIT
fail=0
ok() { if [ "$2" = 0 ]; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi; }

cd "$HERE"
"$MC" stubs input -o "$W/merged.php"; ok "merged rc" $?
"$MC" stubs input/b_namespace.php > "$W/single.php"; ok "single rc" $?
if [ "${1:-}" = "--update" ]; then
    cp "$W/merged.php" expected/merged.php
    cp "$W/single.php" expected/b_namespace.php
fi
cmp -s "$W/merged.php" expected/merged.php; ok "merged == expected/merged.php" $?
cmp -s "$W/single.php" expected/b_namespace.php; ok "single == expected/b_namespace.php" $?
[ "$("$MC" stubs input/a_functions.php input/b_namespace.php input/c_braced.php)" = "$(cat expected/merged.php)" ]
ok "file list == directory (sorted)" $?

"$MC" stubs --keep-lines input/b_namespace.php > "$W/keep.php"
same=0
for decl in 'interface Named' 'final class User extends Base' 'enum Status' 'function helper(' 'function other(' 'protected function id(): int'; do
    a="$(grep -nF -- "$decl" input/b_namespace.php | cut -d: -f1)"
    b="$(grep -nF -- "$decl" "$W/keep.php" | cut -d: -f1)"
    [ -n "$a" ] && [ "$a" = "$b" ] || { echo "  $decl: line $a vs $b"; same=1; }
done
ok "--keep-lines keeps declaration lines" $same

"$MC" stubs >/dev/null 2>&1; [ $? = 64 ]; ok "no paths -> 64" $?
"$MC" stubs --split input >/dev/null 2>&1; [ $? = 64 ]; ok "--split without -o -> 64" $?
"$MC" stubs --split -o "$W/split" input >/dev/null 2>&1 && cmp -s "$W/split/input/b_namespace.php" expected/b_namespace.php
ok "--split writes one stub per file" $?

"$MC" stubs --library -o "$W/lib.php"; ok "library rc" $?
grep -q '^/\* stub: prelude/exceptions.php [0-9a-f]\{12\} \*/' "$W/lib.php" && grep -q '^/\* stub: stubs/stdlib.php \*/' "$W/lib.php" \
    && grep -q '^class Exception implements Throwable' "$W/lib.php" && grep -q '^function str_pad(' "$W/lib.php"
ok "library has prelude + stdlib sections" $?

if command -v php >/dev/null 2>&1; then
    php -l "$W/merged.php" >/dev/null 2>&1; ok "php -l merged" $?
    out="$(php -d xdebug.mode=off -r '
        require $argv[1];
        $ok = function_exists("add") && function_exists("refs") && function_exists("polyfill_one") && function_exists("global_fn")
            && function_exists("Lib\\Util\\slug") && function_exists("Lib\\Util\\maybe") && function_exists("App\\Other\\other")
            && interface_exists("App\\Model\\Named") && trait_exists("App\\Model\\Greets") && enum_exists("App\\Model\\Status")
            && class_exists("App\\Model\\User") && class_exists("App\\Model\\Point")
            && defined("STUB_LIMIT") && defined("STUB_NAME") && !defined("STUB_RUNTIME") && defined("Lib\\Util\\VERSION")
            && (new App\Model\User("x"))->bio === "multi\nline" && App\Model\Status::DEFAULT === App\Model\Status::Active
            && (new ReflectionFunction("greeting"))->getParameters()[0]->getDefaultValue() === "Hello, {name}!";
        echo $ok ? "loaded" : "missing";' "$W/merged.php" 2>&1)"
    [ "$out" = "loaded" ]; ok "php loads merged stubs ($out)" $?
fi
exit $fail
