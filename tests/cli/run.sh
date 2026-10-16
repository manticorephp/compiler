#!/usr/bin/env bash
# CLI golden test. behaviour.txt = what commands DO (captured before the CLI
# restructure; must not change). help.txt = generated usage text (review its diff).
#   bash tests/cli/run.sh            compare
#   bash tests/cli/run.sh --update   rewrite help.txt and the completions.* goldens
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

doctor_case() { # label; PATH for the compiler is only $WORK/shim
    local label="$1" out rc
    out="$(PATH="$WORK/shim" "$MC" doctor --offline --no-smoke 2>&1)"; rc=$?
    printf '== %s\nrc=%s fail=%s warn=%s\n' "$label" "$rc" "$(printf '%s\n' "$out" | grep -c '^\[FAIL\]')" "$(printf '%s\n' "$out" | grep -c '^\[WARN\]')"
}
shim() { rm -rf "$WORK/shim"; mkdir -p "$WORK/shim"; for t in "$@"; do case "$t" in clang14) printf '#!/bin/sh\necho "clang version 14.0.0"\n' > "$WORK/shim/clang";; clang17) printf '#!/bin/sh\necho "clang version 17.0.6"\n' > "$WORK/shim/clang";; pcre2-config) printf '#!/bin/sh\necho 10.42\n' > "$WORK/shim/pcre2-config";; pkg-config) printf '#!/bin/sh\necho 3.0.0\n' > "$WORK/shim/pkg-config";; cc) printf '#!/bin/sh\nexit 0\n' > "$WORK/shim/cc";; esac; done; chmod +x "$WORK/shim"/* 2>/dev/null || true; }

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
    run "compile bad --progress"   "$MC" compile "f.php" -o "x" --progress=bogus
    run "compile bad --color"      "$MC" compile "f.php" -o "x" --color=bogus
    run "split-ir no args"         "$MC" split-ir
    run "build missing manifest"   "$MC" build "nope.json"
    run "upgrade in a dev tree (foreign)" "$MC" upgrade --check
    run "version --check in a dev tree (foreign)" "$MC" version --check
    shim; doctor_case "doctor: empty toolchain"
    shim clang14 cc; doctor_case "doctor: old clang"
    shim clang17 cc; doctor_case "doctor: no pcre2/pkg-config"
    shim clang17 cc pcre2-config pkg-config; doctor_case "doctor: full toolchain"
    run "completions no shell"      "$MC" completions
    run "completions unknown shell" "$MC" completions tcsh
    run "unknown command"          "$MC" frobnicate
    "$MC" compile "f.php" -o "prog" >/dev/null 2>&1
    if [ -x ./prog ]; then out="$(./prog 2>&1)"; else out="no binary"; fi
    printf '== compile+run\n%s\n' "$out"
}

progress_check() { # --progress/--quiet/--timings/--color behaviour; failures print and set fail=1
    local e="$WORK/pe" bad
    ck() { # label, condition-exit-code
        if [ "$2" != 0 ]; then echo "progress FAIL: $1"; fail=1; fi
    }
    "$MC" compile f.php -o pp --progress=plain >"$WORK/po" 2>"$e"; ck "plain rc" $?
    [ ! -s "$WORK/po" ]; ck "plain stdout empty" $?
    bad="$(grep -Evc '^\[ *[0-9]+\] .+  [0-9]+\.[0-9]s$|^done in [0-9]+\.[0-9]s$|^      .+ [0-9]+/[0-9]+$' "$e")"
    [ "$bad" = 0 ]; ck "plain line format" $?
    for s in parse LowerFromAst Verify; do grep -Eq "^\[ *[0-9]+\] $s( |  )" "$e"; ck "plain stage $s" $?; done
    "$MC" compile f.php -o pj --progress=json >/dev/null 2>"$e"; ck "json rc" $?
    bad="$(grep -Evc '^\{"event":"(stage_end|counter|done|timing)".*\}$' "$e")"
    [ "$bad" = 0 ]; ck "json line format" $?
    [ "$(grep -c '"event":"done"' "$e")" = 1 ]; ck "json done once" $?
    ! grep -q "$(printf '\033')" "$e"; ck "json no ESC" $?
    "$MC" compile f.php -o pq --progress=off >/dev/null 2>"$e"; [ ! -s "$e" ]; ck "off silent" $?
    "$MC" compile f.php -o pq --quiet >/dev/null 2>"$e"; [ ! -s "$e" ]; ck "quiet silent" $?
    "$MC" compile f.php -o pq --quiet --progress=plain >/dev/null 2>"$e"; [ ! -s "$e" ]; ck "quiet+plain silent" $?
    env -u CI "$MC" compile f.php -o pa --progress=auto >/dev/null 2>"$e"; [ ! -s "$e" ]; ck "auto piped silent" $?
    CI=1 "$MC" compile f.php -o pa --progress=auto >/dev/null 2>"$e"; grep -Eq '^done in ' "$e"; ck "auto+CI plain" $?
    "$MC" compile f.php -o x --progress=bogus >/dev/null 2>&1; [ $? = 64 ]; ck "bogus progress rc 64" $?
    "$MC" compile f.php -o x --color=bogus >/dev/null 2>&1; [ $? = 64 ]; ck "bogus color rc 64" $?
    local esc; esc="$(printf '\033[')"
    "$MC" compile f.php -o pc --progress=plain --color=always >/dev/null 2>"$e"; grep -qF "$esc" "$e"; ck "always has ESC[" $?
    NO_COLOR=1 "$MC" compile f.php -o pc --progress=plain --color=always >/dev/null 2>"$e"; ! grep -qF "$esc" "$e"; ck "NO_COLOR beats always" $?
    TERM=dumb "$MC" compile f.php -o pc --progress=plain --color=always >/dev/null 2>"$e"; ! grep -qF "$esc" "$e"; ck "TERM=dumb beats always" $?
    "$MC" compile f.php -o pc --progress=plain --color=never >/dev/null 2>"$e"; ! grep -qF "$esc" "$e"; ck "never no ESC" $?
    "$MC" compile f.php -o pc --progress=json --color=always >/dev/null 2>"$e"; ! grep -qF "$esc" "$e"; ck "json+always no ESC" $?
    "$MC" compile f.php -o pt --progress=plain --timings >/dev/null 2>"$e"; grep -q '^timings (longest first):' "$e"; ck "timings table" $?
    local m o1 o2
    "$MC" compile f.php -o p0 --progress=off >"$WORK/o_off" 2>/dev/null
    for m in plain json; do
        "$MC" compile f.php -o "p_$m" --progress=$m >"$WORK/o_$m" 2>/dev/null
        cmp -s "$WORK/o_off" "$WORK/o_$m"; ck "compile stdout same ($m)" $?
        [ "$(./p0)" = "$(./p_$m)" ]; ck "binary output same ($m)" $?
    done
}

helps() {
    for c in "" compile build analyze dump-llvm dump-ast dump-mir dump-llvm-mir dump-sig split-ir version upgrade doctor completions; do
        printf '== help %s\n' "${c:-<root>}"
        if [ -z "$c" ]; then "$MC" help 2>&1; else "$MC" "$c" --help 2>&1; fi
    done
}

completions_check() {
    local sh
    for sh in bash zsh fish; do
        "$MC" completions $sh > "$WORK/c.$sh" 2>/dev/null
        diff -u "$HERE/completions.$sh" "$WORK/c.$sh" || fail=1
    done
    bash -n "$WORK/c.bash" || fail=1
    if command -v zsh >/dev/null 2>&1; then zsh -n "$WORK/c.zsh" || fail=1; fi
    if command -v fish >/dev/null 2>&1; then fish -n "$WORK/c.fish" || fail=1; fi
}

fail=0
if [ "${1:-}" = "--update" ]; then
    helps > "$HERE/help.txt"
    for sh in bash zsh fish; do "$MC" completions $sh > "$HERE/completions.$sh"; done
    echo "help.txt + completions.* updated"; exit 0
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
progress_check
completions_check
[ "$fail" = 0 ] && echo "=== cli golden: ok ===" || { echo "=== cli golden: FAILED ==="; exit 1; }
