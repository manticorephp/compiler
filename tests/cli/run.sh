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

diag() { # label, then the command: rc + stderr (paths stripped), sorted, summary line last
    local label="$1" rc; shift
    env -u CI "$@" >/dev/null 2>"$WORK/e"; rc=$?   # CI=1 turns progress on; goldens hold diagnostics only
    printf '== %s\nrc=%s\n' "$label" "$rc"
    sed -e "s|$WORK/||g" -e "s|$HERE/check/||g" "$WORK/e" | grep -v -e '^front end:' -e '^build:' | sort
    grep '^front end:' "$WORK/e"
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
    shape "dump-llvm-mir" "; ModuleID" "source_filename define probe-stack" "$MC" dump-llvm-mir "f.php"
    shape "dump-llvm"     "; ModuleID" "source_filename define probe-stack" "$MC" dump-llvm "f.php"
    # `$o->__construct(...$a)` on an erased receiver is ONE call to a shared helper even when a single class takes a
    # by-ref constructor parameter (that veto once spliced every class's arm into the caller: a 419 KiB -O0 frame).
    {
        echo '<?php'
        for i in $(seq 0 299); do echo "class C$i { public function __construct(public int \$a = 0, public string \$b = \"\") {} }"; done
        echo 'class R { public function __construct(array &$x = []) {} }'
        echo 'function mk(string $c, array $a) { $o = new $c(); $o->__construct(...$a); return $o; }'
        echo 'echo mk("C3", [1, "x"])->a, "\n"; $z = []; new R($z);'
    } > "$WORK/spread.php"
    printf '== ctor spread stays shared\n'
    n="$("$MC" dump-llvm-mir "$WORK/spread.php" 2>/dev/null | awk '/^define .*@manticore_mk\(/{p=1} p{n++} p&&/^}/{print n; exit}')"
    if [ -n "$n" ] && [ "$n" -lt 400 ]; then echo "ok"; else echo "BAD: mk body ${n:-missing} lines"; fi
    # `$this` handed to an unresolved call (`$r($this)`) is never a reference target, even when some function of
    # the program keeps the reference to its by-ref parameter: it stayed a ref-cell local (a `cell`), the receiver of
    # every `$this->m()` lost its class and OwnershipFlow treated all their arguments as by-ref (Laravel's
    # Model::__call released its pack twice).
    cat > "$WORK/thisref.php" <<'PHP'
<?php
function keep(&$x) { $GLOBALS['k'][] = &$x; }
class M {
    public static $r = [];
    public function g($a) { return self::$r[$a] ?? null; }
    public function h($a) { return 1; }
    public function f($a) { if ($r = $this->g($a)) { return $r($this); } return $this->h($a); }
}
$x = 1; keep($x);
echo (new M)->f(1), "\n";
PHP
    printf '== this is no ref cell\n'
    mir="$("$MC" dump-mir "$WORK/thisref.php" 2>/dev/null)"
    if ! printf '%s\n' "$mir" | grep -q '^fn M__f'; then echo "BAD: no MIR for M::f"
    elif printf '%s\n' "$mir" | grep -q 'load_local this : cell'; then echo "BAD: \$this typed cell"
    else echo "ok"; fi
    shape "analyze json"  "["          "]"                     "$MC" analyze --json "f.php"
    run "dump-ast bogus flag"      "$MC" dump-ast --bogus "f.php"
    run "dump-ast missing file"    "$MC" dump-ast "nope.php"
    run "dump-ast with compile-only flag" "$MC" dump-ast --analyze "f.php"
    run "dump-mir rejects --check"  "$MC" dump-mir --check "f.php"
    run "analyze rejects --check"   "$MC" analyze --check "f.php"
    run "compile bad -O"           "$MC" compile "f.php" -o "x" -O9
    run "compile bad --memory"     "$MC" compile "f.php" -o "x" --memory bogus
    run "compile bad --strict"     "$MC" compile "f.php" -o "x" --strict=bogus
    diag "check_parse_two_files"    sh -c "cd \"$HERE/check/parse2\" && \"$MC\" build --check"
    diag "check_unsupported_three_files" sh -c "cd \"$HERE/check/unsupported3\" && \"$MC\" build --check"
    printf "== analyze_decl_and_type_both\n"; "$MC" analyze --deep --json "$HERE/check/analyze_decl_and_type.php" 2>"$WORK/e" | grep -o "repr.type\|arg.type" | sort; [ -s "$WORK/e" ] && echo "BAD: stderr"
    diag "check_clean"              "$MC" compile --check "$HERE/check/clean.php" -o "$WORK/clean"
    [ -e "$WORK/clean" ] && echo "BAD: --check wrote output"
    diag "check_two_bad_fns_then_good" "$MC" compile --check "$HERE/check/two_bad_then_good.php" -o "$WORK/tb"
    diag "build_two_bad_fns_then_good" "$MC" compile "$HERE/check/two_bad_then_good.php" -o "$WORK/tb"
    [ -e "$WORK/tb" ] && echo "BAD: wrote a binary"
    diag "check_parse_in_body"      "$MC" compile --check "$HERE/check/parse_in_body.php" -o "$WORK/pb"
    diag "check_parse_two_in_body"  "$MC" compile --check "$HERE/check/parse_two_in_body.php" -o "$WORK/p2"
    diag "check_dedup"              "$MC" compile --check "$HERE/check/dedup.php" -o "$WORK/dd"
    diag "check_unresolved_classes" sh -c "cd \"$HERE/check/unresolved_classes\" && \"$MC\" build --check"
    diag "check_known_builtin_classes" "$MC" compile --check "$HERE/check/known_builtin_classes.php" -o "$WORK/kb"
    diag "check_guarded_decls"      "$MC" compile --check "$HERE/check/guarded_decls.php" -o "$WORK/gd"
    diag "check_ref_after_class"    "$MC" compile --check "$HERE/check/ref_after_class_lowered.php" -o "$WORK/rc"
    diag "check_class_alias_literal" "$MC" compile --check "$HERE/check/class_alias_literal.php" -o "$WORK/ca"
    diag "check_unresolved_fns"     "$MC" compile --check "$HERE/check/unresolved_fns.php" -o "$WORK/uf"
    diag "check_unresolved_hints"   "$MC" compile --check "$HERE/check/unresolved_hints.php" -o "$WORK/uh"
    diag "check_uses_distinct_trait"   "$MC" compile --check "$HERE/check/uses_distinct_trait.php" -o "$WORK/ut"
    diag "check_uses_distinct_default" "$MC" compile --check "$HERE/check/uses_distinct_default.php" -o "$WORK/ud"
    diag "check_numeric_class"      "$MC" compile --check "$HERE/check/numeric_class.php" -o "$WORK/nc"
    diag "check_missing_package"    sh -c "cd \"$HERE/check/missing_package\" && \"$MC\" build --check"
    diag "check_unit_top_level"     sh -c "cd \"$HERE/check/unit_top_level\" && \"$MC\" build --check"
    for t in reachable_new dead_fn guards soft method_never method_called default_param default_param_unused dyn_define fold_define cascade class_consts_unused dyn_const_probe callable_array_instance switch_guard guard_helpers static_hierarchy dyn_static_missing unserialize_missing; do
        diag "tier_$t" "$MC" compile --check "$HERE/check/tier/$t.php" -o "$WORK/tr"
    done
    diag "tier_reachable_new_allow_traps" "$MC" compile --check --allow-undefined-traps "$HERE/check/tier/reachable_new.php" -o "$WORK/tr"
    diag "tier_dead_fn_all"  "$MC" compile --check --all "$HERE/check/tier/dead_fn.php" -o "$WORK/tr"
    diag "tier_build_error_no_output" "$MC" compile "$HERE/check/tier/reachable_new.php" -o "$WORK/tr"
    [ -e "$WORK/tr" ] && echo "BAD: wrote a binary"
    for r in basic interface_dispatch magic_and_interfaces callables dynamic_new class_level dyn_fn_name dyn_static attr_reflect unused_interface literal_class param_class literal_fn byref_fn byref_arg_class include_class include_in_fn literal_method param_method dyn_static_call unserialize_new reflection_literal reflection_runtime dyn_static_const; do
        diag "reach_$r" env MANTICORE_REACH_DUMP=1 "$MC" compile --check "$HERE/check/reach/$r.php" -o "$WORK/rh"
    done
    printf '== builtin names in sync\n'; bash "$ROOT/tools/check_builtin_names.sh"
    run "compile bad --progress"  "$MC" compile "f.php" -o "x" --progress=bogus
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

stdout_check() { # echo/fwrite(STDOUT) reach a pipe at once: ordered vs stderr, and not lost on SIGTERM (#544)
    cat > "$WORK/so.php" <<'PHP'
<?php
fwrite(STDOUT, "1\n");
echo "2\n";
fwrite(STDERR, "3\n");
sleep(30);
PHP
    "$MC" compile so.php -o so >/dev/null 2>&1 || { echo "stdout FAIL: compile"; fail=1; return; }
    ./so >"$WORK/so.out" 2>&1 &
    local pid=$! i
    for i in 1 2 3 4 5 6 7 8 9 10; do [ "$(wc -l < "$WORK/so.out")" -ge 3 ] && break; sleep 0.3; done
    kill -TERM "$pid" 2>/dev/null; wait "$pid" 2>/dev/null
    [ "$(tr -d '\n' < "$WORK/so.out")" = "123" ] || { echo "stdout FAIL: got '$(tr '\n' ' ' < "$WORK/so.out")'"; fail=1; }
}
# stdout is stdio-buffered and drained at the points output could otherwise be seen late or out of order (#544)
flush_check() {
    local n=0 name body want got pid i
    # ordered: run to the end, stdout+stderr into ONE file, byte-exact
    while IFS='|' read -r name want body; do
        printf '<?php\n%s\n' "$body" > "$WORK/fo.php"
        "$MC" compile "$WORK/fo.php" -o "$WORK/fo" >/dev/null 2>&1 || { echo "flush FAIL: compile $name"; fail=1; continue; }
        (cd "$WORK" && ./fo >fo.out 2>&1 </dev/null)
        got="$(head -c 200 "$WORK/fo.out" | head -n 3 | tr '\n' ',')"
        [ "$got" = "$want" ] || { echo "flush FAIL: $name: got '$got' want '$want'"; fail=1; }
    done <<'CASES'
exit|a,b,c,|echo "a\n"; fwrite(STDERR, "b\n"); echo "c\n"; exit(3);
errlog|a,b,c,|echo "a\n"; error_log("b"); echo "c\n";
usleep|a,b,c,|echo "a\n"; usleep(1000); fwrite(STDERR, "b\n"); echo "c\n";
uncaught|a,PHP Fatal error:  Uncaught Exception: x,|echo "a\n"; throw new Exception("x");
fwritemix|a,b,c,|fwrite(STDOUT, "a\n"); echo "b\n"; print "c\n";
CASES
    # late: the program blocks for 20 s after printing; the line must be on the pipe within ~3 s
    mkfifo "$WORK/ff"; exec 3<>"$WORK/ff"
    while IFS='|' read -r name want body; do
        printf '<?php\n%s\n' "$body" > "$WORK/fo.php"
        "$MC" compile "$WORK/fo.php" -o "$WORK/fo" >/dev/null 2>&1 || { echo "flush FAIL: compile $name"; fail=1; continue; }
        : > "$WORK/fo.out"
        (cd "$WORK" && exec ./fo >fo.out 2>&1 <&3) &
        pid=$!
        for i in 1 2 3 4 5 6 7 8 9 10; do [ "$(tr '\n' ',' < "$WORK/fo.out")" = "$want" ] && break; sleep 0.3; done
        got="$(tr '\n' ',' < "$WORK/fo.out")"
        kill -TERM "$pid" 2>/dev/null; wait "$pid" 2>/dev/null
        [ "$got" = "$want" ] || { echo "flush FAIL: late $name: got '$got'"; fail=1; }
    done <<'CASES'
usleep|hb,|echo "hb\n"; usleep(20000000);
sleep|hb,|echo "hb\n"; sleep(20);
select|hb,|echo "hb\n"; $r = [STDIN]; $w = null; $e = null; stream_select($r, $w, $e, 20);
fgets|hb,|echo "hb\n"; fgets(STDIN);
cpu|a,b,c,|echo "a\n"; echo "b\n"; for ($u = microtime(true); microtime(true) - $u < 0.3;) {} echo "c\n"; for ($u = microtime(true); microtime(true) - $u < 20;) {}
CASES
    exec 3<&-
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
stdout_check
flush_check
completions_check
[ "$fail" = 0 ] && echo "=== cli golden: ok ===" || { echo "=== cli golden: FAILED ==="; exit 1; }
