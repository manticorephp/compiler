#!/usr/bin/env bash
#
# Manticore parser test runner.
#
# Runs `bin/manticore dump-ast --tolerant --ranges` on every case under
# tests/parser/cases and diffs stdout against tests/parser/expected/<name>.txt.
# Cases named *.strict.php run plain `dump-ast` (no --tolerant/--ranges).
# Cases are invoked with a path RELATIVE to the repo root.
#
# Usage:
#   tests/parser/run.sh                # all cases
#   tests/parser/run.sh valid          # filter substring
#   tests/parser/run.sh -k valid       # filter substring
#   tests/parser/run.sh -v             # verbose: show full diff on fail

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MANTICORE="$ROOT/bin/manticore"
CASES="$ROOT/tests/parser/cases"
EXPECTED="$ROOT/tests/parser/expected"
WORK="$ROOT/tests/parser/.work"
LIMIT=60

VERBOSE=0
FILTER=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose) VERBOSE=1; shift ;;
        -k|--filter)  FILTER="$2"; shift 2 ;;
        -h|--help)    sed -n '2,15p' "$0"; exit 0 ;;
        *) FILTER="$1"; shift ;;
    esac
done

if [[ ! -x "$MANTICORE" ]]; then
    echo "fatal: $MANTICORE not built; run bin/build first" >&2
    exit 1
fi

mkdir -p "$WORK"

# No `timeout` on macOS: kill the child after $LIMIT seconds.
run_limited() {
    "$@" &
    local pid=$!
    ( sleep "$LIMIT"; kill -9 "$pid" 2>/dev/null ) &
    local wd=$!
    wait "$pid" 2>/dev/null
    local rc=$?
    kill "$wd" 2>/dev/null
    wait "$wd" 2>/dev/null
    return $rc
}

cases=()
for f in "$CASES"/*.php; do
    [[ -f "$f" ]] || continue
    n="$(basename "$f" .php)"
    [[ -n "$FILTER" && "$n" != *"$FILTER"* ]] && continue
    cases+=("$n")
done

if [[ ${#cases[@]} -eq 0 ]]; then
    echo "no cases match filter '$FILTER'" >&2
    exit 1
fi

passed=0
failed=0
failed_names=()

for name in "${cases[@]}"; do
    expected="$EXPECTED/$name.txt"
    actual="$WORK/$name.txt"
    rel="tests/parser/cases/$name.php"

    if [[ ! -f "$expected" ]]; then
        echo "SKIP $name (no expected output)"
        continue
    fi

    flags="--tolerant --ranges"
    [[ "$name" == *.strict ]] && flags=""
    [[ "$name" == names* ]] && flags="--tolerant --names"

    run_limited "$MANTICORE" dump-ast $flags "$rel" > "$actual" 2>&1
    rc=$?

    # range invariants + name table: --check-ranges and --check-names must print nothing and exit 0
    range_bad=0
    if [[ "$name" != *.strict ]]; then
        chk="$WORK/$name.check"
        run_limited "$MANTICORE" dump-ast --tolerant --check-ranges "$rel" > "$chk" 2>&1
        [[ $? -ne 0 || -s "$chk" ]] && range_bad=1
        run_limited "$MANTICORE" dump-ast --tolerant --check-names "$rel" > "$chk" 2>&1
        [[ $? -ne 0 || -s "$chk" ]] && range_bad=1
    fi

    # a tolerant case must terminate (the watchdog kill is 137) and exit 0
    if [[ "$name" != *.strict && $rc -ne 0 ]]; then
        failed=$((failed + 1))
        failed_names+=("$name")
        printf 'FAIL %s  (exit %d)\n' "$name" "$rc"
        continue
    fi

    if diff -q "$expected" "$actual" > /dev/null 2>&1 && [[ $range_bad -eq 0 ]]; then
        passed=$((passed + 1))
        printf 'PASS %s\n' "$name"
    elif [[ $range_bad -eq 1 ]] && diff -q "$expected" "$actual" > /dev/null 2>&1; then
        failed=$((failed + 1))
        failed_names+=("$name")
        printf 'FAIL %s  (--check-ranges or --check-names not clean)\n' "$name"
        head -n 6 "$WORK/$name.check" | sed 's/^/      /'
    else
        failed=$((failed + 1))
        failed_names+=("$name")
        printf 'FAIL %s  (output mismatch)\n' "$name"
        if [[ $VERBOSE -eq 1 ]]; then
            echo "      ---- expected ----"; sed 's/^/      /' "$expected"
            echo "      ---- actual ----";   head -n 80 "$actual" | sed 's/^/      /'
        else
            (diff "$expected" "$actual" || true) | head -6 | sed 's/^/      /' || true
        fi
    fi
done

# Stress: adversarial inputs must terminate (watchdog) and exit 0 in tolerant mode.
rep() { awk -v s="$1" -v n="$2" 'BEGIN { for (i = 0; i < n; i++) printf "%s", s }'; }
stress() {
    local name="$1" f="$WORK/stress_$1.php"
    [[ -n "$FILTER" && "stress_$name" != *"$FILTER"* ]] && return
    cat > "$f"
    run_limited "$MANTICORE" dump-ast --tolerant "$f" > "$WORK/stress_$name.out" 2>&1
    local rc=$?
    if [[ $rc -eq 0 ]]; then passed=$((passed + 1)); printf 'PASS stress_%s\n' "$name"
    else failed=$((failed + 1)); failed_names+=("stress_$name"); printf 'FAIL stress_%s  (exit %d)\n' "$name" "$rc"; fi
    rm -f "$f" "$WORK/stress_$name.out"
}
stress deep_parens < <({ printf '<?php\n$a = '; rep '(' 5000; })
stress deep_braces < <({ printf '<?php\n'; rep '{' 5000; })
stress deep_brackets < <({ printf '<?php\n$a = '; rep '[' 5000; })
stress deep_if < <({ printf '<?php\n'; rep 'if (1) ' 3000; })
stress stray_run < <({ printf '<?php\n'; rep ') } ] ; , ' 3000; })
stress class_garbage < <({ printf '<?php\nclass A {\n'; rep 'public function f( { ) ' 500; })
stress switch_garbage < <({ printf '<?php\nswitch ($a) {\n'; rep ') case 1: } ' 500; })
stress match_garbage < <({ printf '<?php\n'; rep 'match(1){ 1 => , ' 500; })
stress params_garbage < <({ printf '<?php\n'; rep 'function f(,, $a = , ' 500; })
stress array_garbage < <({ printf '<?php\n$x = [1,'; rep ',, => & ... ' 500; })
stress trait_garbage < <({ printf '<?php\nclass A { use T { '; rep 'a insteadof ; as ' 300; })
stress template_garbage < <({ printf '<html>\n<?php if ($a): ?>\n'; rep '<?php foreach ($b): else: endwhile; ?> x ' 300; })
stress at_chain < <({ printf '<?php\n$a = '; rep '@' 200000; })
stress tilde_chain < <({ printf '<?php\n$a = '; rep '~' 200000; })
stress cast_chain < <({ printf '<?php\n$a = '; rep '(int)' 100000; })
stress minus_chain < <({ printf '<?php\n$a = '; rep '-' 100000; })
stress assign_chain < <({ printf '<?php\n'; rep '$a = ' 50000; printf '1;\n'; })
stress pow_chain < <({ printf '<?php\n$a = '; rep '1 ** ' 50000; printf '1;\n'; })
stress ternary_chain < <({ printf '<?php\n$a = '; rep '1 ? ' 50000; })
stress arrow_chain < <({ printf '<?php\n$a = '; rep 'fn() => ' 20000; })
stress array_open < <({ printf '<?php\n$a = '; rep '[' 100000; })
stress new_chain < <({ printf '<?php\n$a = '; rep 'new ' 50000; })
stress closure_chain < <({ printf '<?php\n$a = '; rep 'function(){' 20000; })
stress yield_chain < <({ printf '<?php\nfunction f() { '; rep 'yield ' 50000; })
stress throw_print_clone < <({ printf '<?php\n$a = '; rep 'clone print throw ' 20000; })
stress heredoc_interp < <(printf '<?php\n$a = <<<X\n{$a')
stress string_interp_open < <(printf '<?php\n$a = "{$a->b[')
stress string_interp_deep < <({ printf '<?php\n$a = "{$a'; rep '[' 20000; })
stress type_nesting < <({ printf '<?php\nfunction f('; rep '(' 50000; })
stress stmt_nesting < <({ printf '<?php\n'; rep 'while (1) ' 100000; })
stress try_garbage < <({ printf '<?php\n'; rep 'try { catch (' 2000; })
stress declare_garbage < <({ printf '<?php\n'; rep 'declare( ' 2000; })

stress paren_open < <({ printf '<?php\n$a = '; rep '(' 100000; })
stress bang_chain < <({ printf '<?php\n$a = '; rep '!' 100000; })
stress star_chain < <({ printf '<?php\n$a = 1 '; rep '* ' 100000; })
stress xor_chain < <({ printf '<?php\n$a = 1 '; rep 'xor ' 100000; })
stress coalesce_chain < <({ printf '<?php\n$a = 1 '; rep '?? ' 100000; })
stress call_chain < <({ printf '<?php\n$a = '; rep '()' 100000; })

stress else_if_chain < <({ printf '<?php\nif (1) {}'; rep ' else if (1) {}' 100000; })
stress anon_class_nest < <({ printf '<?php\n$a = '; rep 'new class { function f() { return ' 20000; })
stress switch_nest < <({ printf '<?php\n'; rep 'switch (1) { case 1: ' 50000; })
stress try_nest < <({ printf '<?php\n'; rep 'try { ' 50000; })
stress match_nest < <({ printf '<?php\n$a = '; rep 'match (1) { 1 => ' 50000; })
stress attribute_nest < <({ printf '<?php\n'; rep '#[A(' 50000; })
stress namespace_nest < <({ printf '<?php\n'; rep 'namespace A { ' 50000; })
stress alt_if_nest < <({ printf '<?php\n'; rep 'if (1): ' 50000; })
stress alt_foreach_else < <({ printf '<?php\n'; rep 'foreach ($a as $b): if (1): else: ' 30000; })
stress elseif_chain < <({ printf '<?php\nif (1) {}'; rep ' elseif (1) {}' 100000; })
stress property_hook_nest < <({ printf '<?php\nclass A { public $p { get { '; rep 'class B { public $q { get { ' 20000; })
stress do_while_nest < <({ printf '<?php\n'; rep 'do { ' 100000; })

# Error cap: at most 100 errors + one final "too many errors", exit 0.
flood() {
    local name="$1" f="$WORK/flood_$1.php"
    [[ -n "$FILTER" && "flood_$name" != *"$FILTER"* ]] && return
    cat > "$f"
    run_limited "$MANTICORE" dump-ast --tolerant "$f" > "$WORK/flood_$name.out" 2>&1
    local rc=$?
    local n last
    n="$(grep -c '^error ' "$WORK/flood_$name.out")"
    last="$(grep '^error ' "$WORK/flood_$name.out" | tail -n 1)"
    if [[ $rc -eq 0 && $n -eq 101 && "$last" == *"too many errors" ]]; then passed=$((passed + 1)); printf 'PASS flood_%s\n' "$name"
    else failed=$((failed + 1)); failed_names+=("flood_$name"); printf 'FAIL flood_%s  (exit %d, %d errors, last: %s)\n' "$name" "$rc" "$n" "$last"; fi
    rm -f "$f" "$WORK/flood_$name.out"
}
flood parser_errors < <({ printf '<?php\n'; rep 'foo(;\n' 300; })
flood lexer_errors < <({ printf '<?php\n'; rep '\001' 300; })
flood mixed_errors < <({ printf '<?php\n'; rep 'foo(;\n\001\n' 400; })
flood lexer_fills_cap < <({ printf '<?php\n'; rep '\001' 150; rep 'foo(;\n' 50; })

# Valid deep input is valid: no error from --tolerant nor from analyze (the nesting cap sits far above real code).
clean() {
    local name="$1" f="$WORK/clean_$1.php"
    [[ -n "$FILTER" && "clean_$name" != *"$FILTER"* ]] && return
    cat > "$f"
    run_limited "$MANTICORE" dump-ast --tolerant "$f" > "$WORK/clean_$name.out" 2>&1
    local rc=$? n
    n="$(grep -c '^error ' "$WORK/clean_$name.out")"
    run_limited "$MANTICORE" analyze --only parse.error "$f" > "$WORK/clean_$name.an" 2>&1
    if [[ $rc -eq 0 && $n -eq 0 ]] && ! grep -q 'parse.error' "$WORK/clean_$name.an"; then passed=$((passed + 1)); printf 'PASS clean_%s\n' "$name"
    else failed=$((failed + 1)); failed_names+=("clean_$name"); printf 'FAIL clean_%s  (exit %d, %d errors)\n' "$name" "$rc" "$n"; grep '^error ' "$WORK/clean_$name.out" | head -n 2 | sed 's/^/      /'; fi
    rm -f "$f" "$WORK/clean_$name.out" "$WORK/clean_$name.an"
}
clean arrays_85 < <({ printf '<?php\n$a = '; rep '[' 85; printf '1'; rep ']' 85; printf ';\n'; })
clean arrays_200 < <({ printf '<?php\n$a = '; rep '[' 200; printf '1'; rep ']' 200; printf ';\n'; })
clean else_if_300 < <({ printf '<?php\nif (1) {}'; rep ' else if (1) {}' 300; printf '\n'; })
clean coalesce_300 < <({ printf '<?php\n$a = 1'; rep ' ?? 1' 300; printf ';\n'; })
clean concat_300 < <({ printf '<?php\n$a = 1'; rep ' . 1' 300; printf ';\n'; })
clean calls_150 < <({ printf '<?php\n$a = '; rep 'f(' 150; printf '1'; rep ')' 150; printf ';\n'; })
clean parens_150 < <({ printf '<?php\n$a = '; rep '(' 150; printf '1'; rep ')' 150; printf ';\n'; })

# Past the cap exactly ONE error is recorded and the rest of the statement is skipped.
onecap() {
    local name="$1" f="$WORK/onecap_$1.php"
    [[ -n "$FILTER" && "onecap_$name" != *"$FILTER"* ]] && return
    cat > "$f"
    run_limited "$MANTICORE" dump-ast --tolerant "$f" > "$WORK/onecap_$name.out" 2>&1
    local rc=$? n
    n="$(grep -c '^error ' "$WORK/onecap_$name.out")"
    if [[ $rc -eq 0 && $n -eq 1 ]]; then passed=$((passed + 1)); printf 'PASS onecap_%s\n' "$name"
    else failed=$((failed + 1)); failed_names+=("onecap_$name"); printf 'FAIL onecap_%s  (exit %d, %d errors)\n' "$name" "$rc" "$n"; fi
    rm -f "$f" "$WORK/onecap_$name.out"
}
onecap arrays < <({ printf '<?php\n$a = '; rep '[' 5000; printf '1'; rep ']' 5000; printf ';\n$b = 1;\n'; })
onecap parens < <({ printf '<?php\n$a = '; rep '(' 5000; printf '1'; rep ')' 5000; printf ';\n$b = 1;\n'; })
onecap blocks < <({ printf '<?php\n'; rep '{' 5000; rep '}' 5000; printf '\n$b = 1;\n'; })

# Strict (non-tolerant) parse with ranges: the end()/endFrom() clamps must never need to fire.
for f in "$ROOT/src/Parser/Parser.php" "$ROOT/src/Parser/Dump.php" "$CASES"/*.strict.php; do
    [[ "$(basename "$f")" == error_* ]] && continue   # a syntax-error file has no tree to check
    [[ -f "$f" ]] || continue
    [[ -n "$FILTER" && "strict_ranges" != *"$FILTER"* ]] && continue
    run_limited "$MANTICORE" dump-ast --check-ranges "$f" > "$WORK/strict_ranges.out" 2>&1
    if [[ $? -eq 0 && ! -s "$WORK/strict_ranges.out" ]]; then passed=$((passed + 1)); printf 'PASS strict_ranges %s\n' "$(basename "$f")"
    else failed=$((failed + 1)); failed_names+=("strict_ranges:$(basename "$f")"); printf 'FAIL strict_ranges %s\n' "$(basename "$f")"; head -n 3 "$WORK/strict_ranges.out" | sed 's/^/      /'; fi
done
rm -f "$WORK/strict_ranges.out"

# RangeCheck self-test on hand-built trees (needs the Zend php; SKIP without it)
if command -v php > /dev/null 2>&1; then
    if [[ -z "$FILTER" || "rangecheck_selftest" == *"$FILTER"* ]]; then
        if php -d xdebug.mode=off "$ROOT/tests/parser/rangecheck_selftest.php" > "$WORK/selftest.txt" 2>&1; then
            passed=$((passed + 1)); printf 'PASS rangecheck_selftest\n'
        else
            failed=$((failed + 1)); failed_names+=("rangecheck_selftest")
            printf 'FAIL rangecheck_selftest\n'; head -n 10 "$WORK/selftest.txt" | sed 's/^/      /'
        fi
    fi
else
    echo "SKIP rangecheck_selftest (php not found)"
fi

echo "---"
printf 'passed: %d  failed: %d  total: %d\n' "$passed" "$failed" "$((passed + failed))"
if [[ $failed -gt 0 ]]; then
    printf 'failures: %s\n' "${failed_names[*]}"
    exit 1
fi
