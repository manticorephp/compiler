#!/usr/bin/env bash
# CLI golden test. behaviour.txt = what commands DO; help.txt = generated usage text
# (review its diff). INVARIANT: no codegen-dependent value (checksum, size, IR text)
# may appear in behaviour.txt -- compiler output is checked structurally by shape()
# and only "ok rc=0 markers=..." is recorded, so a codegen/stdlib change never edits it.
#   bash tests/cli/run.sh            compare
#   bash tests/cli/run.sh --update   rewrite help.txt and the completions.* goldens
#   bash tests/cli/run.sh --capture  rewrite behaviour.txt (CLI-behaviour changes only)
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
shape() { # label, first-line prefix, fixed-string markers, then the command: rc 0 + non-empty stdout + first line + markers
    local label="$1" first="$2" markers="$3" rc bad="" m; shift 3
    "$@" >"$WORK/o" 2>/dev/null; rc=$?
    [ $rc = 0 ] || bad="rc=$rc"
    [ -s "$WORK/o" ] || bad="$bad empty"
    case "$(head -n1 "$WORK/o")" in "$first"*) ;; *) bad="$bad first-line";; esac
    for m in $markers; do grep -qF -- "$m" "$WORK/o" || bad="$bad missing:$m"; done
    printf '== %s\n' "$label"
    if [ -z "$bad" ]; then echo "ok rc=0 first='$first' markers='$markers'"; else echo "BAD:$bad"; fi
}

doctor_case() { # label; PATH for the compiler is only $WORK/shim
    local label="$1" out rc
    out="$(PATH="$WORK/shim" "$MC" doctor --offline --no-smoke 2>&1)"; rc=$?
    printf '== %s\nrc=%s fail=%s warn=%s\n' "$label" "$rc" "$(printf '%s\n' "$out" | grep -c '^\[FAIL\]')" "$(printf '%s\n' "$out" | grep -c '^\[WARN\]')"
}
shim() { rm -rf "$WORK/shim"; mkdir -p "$WORK/shim"; for t in "$@"; do case "$t" in clang14) printf '#!/bin/sh\necho "clang version 14.0.0"\n' > "$WORK/shim/clang";; clang17) printf '#!/bin/sh\necho "clang version 17.0.6"\n' > "$WORK/shim/clang";; pcre2-config) printf '#!/bin/sh\necho 10.42\n' > "$WORK/shim/pcre2-config";; pkg-config) printf '#!/bin/sh\necho 3.0.0\n' > "$WORK/shim/pkg-config";; cc) printf '#!/bin/sh\nexit 0\n' > "$WORK/shim/cc";; esac; done; chmod +x "$WORK/shim"/* 2>/dev/null || true; }

version_case() { # derived from COMPILER_VERSION: a release needs no golden edit
    local got rc; got="$("$MC" version 2>/dev/null)"; rc=$?
    printf '== version\n'
    if [ "$got" = "manticore $(cat "$ROOT/COMPILER_VERSION")" ]; then echo "matches COMPILER_VERSION rc=$rc"; else echo "MISMATCH '$got' rc=$rc"; fi
}

behaviour() {
    version_case
    shape "dump-ast"      "Program"    "FunctionDecl"          "$MC" dump-ast "f.php"
    shape "dump-mir"      "; passes:"  "add("                  "$MC" dump-mir "f.php"
    shape "dump-sig"      "{\"schema\":" "\"functions\":["    "$MC" dump-sig "f.php"
    shape "dump-llvm-mir" "; ModuleID" "source_filename define" "$MC" dump-llvm-mir "f.php"
    shape "dump-llvm"     "; ModuleID" "source_filename define" "$MC" dump-llvm "f.php"
    shape "analyze json"  "["          "]"                     "$MC" analyze --json "f.php"
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
    # Host- or timing-dependent checks (core count, polling cadence, redraw throttle, pty):
    # a miss is reported but never fails the run. A hard check must hold on every runner.
    soft() { # label, condition-exit-code
        if [ "$2" != 0 ]; then echo "progress WARN (soft): $1"; fi
    }
    "$MC" compile f.php -o pp --progress=plain >"$WORK/po" 2>"$e"; ck "plain rc" $?
    [ ! -s "$WORK/po" ]; ck "plain stdout empty" $?
    bad="$(grep -Evc '^\[ *[0-9]+\] .+  [0-9]+\.[0-9]s$|^done in [0-9]+\.[0-9]s$|^      .+ [0-9]+/[0-9]+$' "$e")"
    [ "$bad" = 0 ]; ck "plain line format" $?
    for s in parse LowerFromAst Verify; do grep -Eq "^\[ *[0-9]+\] $s( |  )" "$e"; ck "plain stage $s" $?; done
    "$MC" compile f.php -o pj --progress=json >/dev/null 2>"$e"; ck "json rc" $?
    bad="$(grep -Evc '^\{"event":"(stage_start|stage_end|counter|done|timing)".*\}$' "$e")"
    [ "$bad" = 0 ]; ck "json line format" $?
    [ "$(grep -c '"event":"done"' "$e")" = 1 ]; ck "json done once" $?
    [ -s "$e" ] && ! grep -q "$(printf '\033')" "$e"; ck "json no ESC" $?
    "$MC" compile f.php -o pq --progress=off >/dev/null 2>"$e"; [ ! -s "$e" ]; ck "off silent" $?
    "$MC" compile f.php -o pq --quiet >/dev/null 2>"$e"; [ ! -s "$e" ]; ck "quiet silent" $?
    "$MC" compile f.php -o pq --quiet --progress=plain >/dev/null 2>"$e"; [ ! -s "$e" ]; ck "quiet+plain silent" $?
    env -u CI "$MC" compile f.php -o pa --progress=auto >/dev/null 2>"$e"; [ ! -s "$e" ]; ck "auto piped silent" $?
    CI=1 "$MC" compile f.php -o pa --progress=auto >/dev/null 2>"$e"; grep -Eq '^done in ' "$e"; ck "auto+CI plain" $?
    local rc
    "$MC" compile f.php -o x --progress=bogus >/dev/null 2>"$e"; rc=$?; [ $rc = 64 ] && grep -qxF "compile: unknown --progress value 'bogus' (expected auto|live|plain|json|off)" "$e"; ck "bogus progress rc 64 + text" $?
    "$MC" compile f.php -o x --color=bogus >/dev/null 2>"$e"; rc=$?; [ $rc = 64 ] && grep -qxF "compile: unknown --color value 'bogus' (expected auto|always|never)" "$e"; ck "bogus color rc 64 + text" $?
    "$MC" build nope.json --progress=bogus >/dev/null 2>"$e"; rc=$?; [ $rc = 64 ] && grep -qxF "build: unknown --progress value 'bogus' (expected auto|live|plain|json|off)" "$e"; ck "build bogus progress rc 64 + text" $?
    local esc; esc="$(printf '\033[')"
    colour() { # label, expect (yes|no), then env/args: stderr must be non-empty, rc 0, ESC[ present iff expect=yes
        local label="$1" want="$2" rc2 has; shift 2
        "$@" >/dev/null 2>"$e"; rc2=$?
        grep -qF "$esc" "$e" && has=yes || has=no
        [ $rc2 = 0 ] && [ -s "$e" ] && [ "$has" = "$want" ]; ck "$label" $?
    }
    colour "always has ESC[" yes "$MC" compile f.php -o pc --progress=plain --color=always
    colour "NO_COLOR beats always" no env NO_COLOR=1 "$MC" compile f.php -o pc --progress=plain --color=always
    colour "TERM=dumb beats always" no env TERM=dumb "$MC" compile f.php -o pc --progress=plain --color=always
    colour "never no ESC" no "$MC" compile f.php -o pc --progress=plain --color=never
    colour "json+always no ESC" no "$MC" compile f.php -o pc --progress=json --color=always
    "$MC" compile f.php -o pt --progress=plain --timings >/dev/null 2>"$e"; grep -q '^timings (longest first):' "$e"; ck "timings table" $?
    for s in parse EmitLlvm link; do grep -Eq "^\[ *[0-9]+\] $s( |  )" "$e"; ck "plain stage $s (single-file)" $?; done
    "$MC" compile f.php -o pj --progress=json >/dev/null 2>"$e"
    # sed reads to EOF: `head -n1` here closes the pipe early and `pipefail` turns the SIGPIPE into a failure on BusyBox
    [ "$(grep -v '"event":"counter"' "$e" | sed -n 1p | grep -c '"event":"stage_start"')" = 1 ]; ck "json first non-counter line stage_start" $?
    tail -n1 "$e" | grep -q '"event":"done"'; ck "json last line done" $?
    awk -F'"t_ms":' '/"event":"stage_start"/ { t = $2 + 0; if (t < p) bad = 1; p = t } END { exit bad }' "$e"; ck "json stage_start t_ms non-decreasing" $?
    for s in parse LowerFromAst EmitLlvm link Verify; do
        [ "$(grep -c "\"event\":\"stage_start\",\"name\":\"$s\"" "$e")" = "$(grep -c "\"event\":\"stage_end\",\"name\":\"$s\"" "$e")" ] \
            && grep -q "\"event\":\"stage_start\",\"name\":\"$s\"" "$e"; ck "json start/end paired: $s" $?
        awk -v n="\"name\":\"$s\"" '/"event":"stage_start"/ && index($0, n) { s++ } /"event":"stage_end"/ && index($0, n) { if (s == 0) bad = 1 } END { exit bad }' "$e"; ck "json start before end: $s" $?
    done
    local lab
    for lab in files functions; do
        grep -q "\"event\":\"counter\",\"label\":\"$lab\"" "$e"; ck "json counter $lab present" $?
        grep "\"label\":\"$lab\"" "$e" | sed 's/.*"done":\([0-9]*\),"total":\([0-9]*\).*/\1 \2/' \
            | awk '{ if ($1 > $2) bad = 1; d = $1; t = $2 } END { exit (bad || d != t) }'; ck "json counter $lab done<=total, final done==total" $?
    done
    "$MC" compile f.php -o pl --progress=live 2>&1 >/dev/null | cat -v >"$e"
    LC_ALL=C grep -qF '^M' "$e"; ck "live has CR" $?
    LC_ALL=C grep -qF '^[[2K' "$e"; ck "live has ESC[2K" $?
    LC_ALL=C grep -qF 'M-^WM-^O parse' "$e"; ck "live shows running stage" $?
    tail -n1 "$e" | LC_ALL=C grep -q 'done in'; ck "live ends with done in" $?
    "$MC" compile f.php -o pl --progress=live --color=always 2>&1 >/dev/null | cat -v >"$e"
    LC_ALL=C grep -qF '^[[36m' "$e"; ck "live colour cyan" $?
    LC_ALL=C grep -qF '^[[32m' "$e"; ck "live colour green" $?
    local m
    "$MC" compile f.php -o p0 --progress=off >"$WORK/o_off" 2>/dev/null
    for m in plain json; do
        "$MC" compile f.php -o "p_$m" --progress=$m >"$WORK/o_$m" 2>/dev/null
        cmp -s "$WORK/o_off" "$WORK/o_$m"; ck "compile stdout same ($m)" $?
        [ "$(./p0)" = "$(./p_$m)" ]; ck "binary output same ($m)" $?
    done
    # split build: MANTICORE_SPLIT_JOBS and -j 0 are BOTH required for a split; the object
    # cache MUST be off (a cache hit never runs clang, so the polling path would go untested)
    export MANTICORE_OBJ_CACHE=0 MANTICORE_OBJ_CACHE_DIR="$WORK/objcache"
    { echo '<?php'; for i in $(seq 1 120); do echo "function g$i(int \$x): int { \$s = 0; for (\$k = 0; \$k < \$x; \$k++) { \$s += \$k * $i + strlen((string)\$k); } return \$s; }"; done; echo 'echo g1(10) + g120(5), "\n";'; } > sp.php
    splitck() { # label, stderr file: >=2 clang counters, total>=2 (a split really happened), starts below total, ends at total
        grep '"label":"clang"' "$2" | sed 's/.*"done":\([0-9]*\),"total":\([0-9]*\).*/\1 \2/' \
            | awk 'NR == 1 { f = $1 } { if ($1 > $2) bad = 1; d = $1; t = $2; n++ } END { exit (bad || n < 2 || t < 2 || f >= t || d != t) }'; soft "$1" $?
    }
    MANTICORE_SPLIT_JOBS=4 "$MC" compile sp.php -o sp_off -j 0 --progress=off >/dev/null 2>&1; ck "split off rc" $?
    MANTICORE_SPLIT_JOBS=4 "$MC" compile sp.php -o sp_pl -j 0 --progress=plain >/dev/null 2>"$e"; ck "split plain rc" $?
    # awk, not a `\1` backreference in an ERE: BusyBox grep does not match it. Depends on the core count, so soft.
    awk '/^      clang [0-9]+\/[0-9]+$/ { split($2, a, "/"); if (a[1] == a[2] && a[1] >= 2) ok = 1 } END { exit !ok }' "$e"; soft "split plain clang N/N tally (N>=2)" $?
    MANTICORE_SPLIT_JOBS=4 "$MC" compile sp.php -o sp_js -j 0 --progress=json >/dev/null 2>"$e"; ck "split json rc" $?
    splitck "split json clang counters (compile)" "$e"
    [ "$(./sp_off)" = "$(./sp_pl)" ] && [ "$(./sp_off)" = "$(./sp_js)" ]; ck "split binary output same" $?
    # site 2: the manifest build splits through assemble_ir_file_split
    mkdir -p mf/src
    cp sp.php mf/src/app.php
    printf '{"applications":[{"name":"d","src":"src","entry":"src/app.php","output":"bin/d"}]}\n' > mf/manticore.json
    ( cd mf && MANTICORE_SPLIT_JOBS=4 "$MC" build --progress=json >/dev/null 2>"$e" ); ck "split build rc" $?
    splitck "split json clang counters (build)" "$e"
    [ "$(./sp_off)" = "$(./mf/bin/d)" ]; ck "split build binary output same" $?
    [ -z "$(find . -name '*.done' -o -name '*.pid')" ]; ck "no stale .done/.pid markers" $?
    pairck() { # label, stderr file: every stage_start name has exactly one stage_end of the same name, and vice versa
        sed -n 's/.*"event":"stage_start","name":"\(.*\)","t_ms.*/\1/p' "$2" | sort >"$WORK/ps"
        sed -n 's/.*"event":"stage_end","name":"\(.*\)","ms.*/\1/p' "$2" | sort >"$WORK/pend"
        [ -s "$WORK/ps" ] && cmp -s "$WORK/ps" "$WORK/pend"; ck "$1" $?
    }
    MANTICORE_SPLIT_JOBS=4 "$MC" compile sp.php -o sp_js2 -j 0 --progress=json >/dev/null 2>"$e"; pairck "json stage pairing (split compile)" "$e"
    ( cd mf && MANTICORE_SPLIT_JOBS=4 "$MC" build --progress=json >/dev/null 2>"$e" ); pairck "json stage pairing (build)" "$e"
    unset MANTICORE_OBJ_CACHE MANTICORE_OBJ_CACHE_DIR
    "$MC" compile f.php -o pq --progress=json >/dev/null 2>"$e"; pairck "json stage pairing (plain compile)" "$e"
    # after link ends the live line must not fall back to a stale outer stage
    "$MC" compile f.php -o pk --progress=live 2>&1 >/dev/null | LC_ALL=C tr '\r' '\n' | LC_ALL=C sed 's/\x1b\[2K//g' | grep -v '^$' | cat -v >"$e"
    LC_ALL=C awk '/done in/ { bad = (prev ~ /narrow round/) } { prev = $0 } END { exit bad }' "$e"; ck "live: no stale stage before done" $?
    # stats lines start at column 0 under a forced live line; auto + stats falls back to plain
    MANTICORE_STATS=1 "$MC" compile f.php -o ps --progress=live 2>&1 >/dev/null | LC_ALL=C tr '\r' '\n' | LC_ALL=C sed 's/\x1b\[2K//g' >"$e"
    [ -s "$e" ] && awk '/stats:/ { n++; if ($0 !~ /^stats:/) bad = 1 } END { exit (bad || n == 0) }' "$e"; ck "live+stats: stats lines start at column 0" $?
    # TTY + stats: auto must fall back to plain (no redraws). Best-effort under a pty; SKIP when script(1) is unusable.
    if command -v script >/dev/null 2>&1; then
        MANTICORE_STATS=1 script -q /dev/null "$MC" compile f.php -o pt --progress=auto >"$e" 2>&1 </dev/null
        if ! grep -aq 'stats:' "$e"; then
            MANTICORE_STATS=1 script -qc "$MC compile f.php -o pt --progress=auto" /dev/null >"$e" 2>&1 </dev/null
        fi
        if grep -aq 'stats:' "$e"; then
            tr -d '\r' <"$e" >"$e.n"
            LC_ALL=C grep -aq '^\[ *[0-9]*\] parse' "$e.n" && ! LC_ALL=C grep -aq "$(printf '\033')\[2K" "$e.n"; soft "tty+stats: auto is plain" $?
        else
            echo "SKIP: tty+stats auto check (script(1) pty unusable)"
        fi
    else
        echo "SKIP: tty+stats auto check (no script(1))"
    fi
    # nested stage end must not blank the live line: the first line drawn after `property scan` ends shows the outer EmitLlvm
    "$MC" compile f.php -o pn --progress=live 2>&1 >/dev/null | LC_ALL=C tr '\r' '\n' | cat -v >"$e"
    # redraws are throttled to >=100 ms, so on a fast host the line may not be redrawn right after the nested end: soft
    LC_ALL=C awk '/property scan/ { seen = 1; next } seen && /M-\^WM-\^O/ { ok = /EmitLlvm/; exit } END { exit !ok }' "$e"; soft "live shows outer stage right after nested end" $?
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
