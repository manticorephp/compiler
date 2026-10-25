#!/usr/bin/env bash
#
# Manticore parser fuzz: tolerant `dump-ast` must never crash, hang or break range invariants.
#
# Usage: tests/parser/fuzz.sh [-n CUTS] [-k substr]
#   -n CUTS    truncation offsets per file (default 8)
#   -k substr  only files whose path contains substr
#
# 1. truncation: every sampled src/ (every 3rd) and tests/aot/cases (every 25th) .php file
#    is cut at CUTS evenly spaced byte offsets. Each cut must exit 0 within the watchdog,
#    be --check-ranges clean, and report at least one `error` line unless the tail is
#    legitimately complete (ends in `;` `}` `?>`, sits in a `//`/`#` comment line, or is
#    inline HTML after the last `?>`, or nothing follows the last `<?php`).
# 2. prefix property: top-level statements (dump lines indented exactly 2 spaces) of the
#    cut dump that precede its first top-level Stmt:Error must be byte-identical to the
#    same-index statements of the full file's dump. A cut dump with no Stmt:Error excludes
#    its LAST top-level statement (the one the cut interrupted may be reshaped, e.g. a
#    class swallowing the rest). The full dump must exit 0 and be non-empty; the run fails
#    if fewer than 10 statements per 100 cuts were compared (the check cannot pass vacuously;
#    a normal run compares ~7 per cut), or if any worker died without reporting.
# 3. garbage: fixed-seed random byte strings, token-pool shuffles and word shuffles of real
#    files terminate, exit 0 and are --check-ranges clean.
# 4. deep nesting (10000 `(`) and error flood (>100 errors: parser, lexer, mixed).
#
# Failing garbage inputs are kept as $TMPDIR/mcfuzz-fail-<name>.php (path printed).

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
MANTICORE="$ROOT/bin/manticore"
LIMIT=20
CUTS=8
FILTER=""

_pid=""
_wd=""
reap() {
    [[ -n "$_pid" ]] && kill -9 "$_pid" 2>/dev/null
    if [[ -n "$_wd" ]]; then pkill -P "$_wd" 2>/dev/null; kill "$_wd" 2>/dev/null; fi
}
run_limited() {
    "$@" &
    _pid=$!
    ( sleep "$LIMIT"; kill -9 "$_pid" 2>/dev/null ) 2>/dev/null &
    _wd=$!
    wait "$_pid" 2>/dev/null
    local rc=$?
    pkill -P "$_wd" 2>/dev/null
    kill "$_wd" 2>/dev/null
    wait "$_wd" 2>/dev/null
    _pid=""; _wd=""
    return $rc
}

# top-level statement blocks, one line each (lines joined by \001), up to the first non-indented line
blocks() {
    LC_ALL=C awk '
        /^Program/ { next }
        /^  Stmt:/ { if (b != "") print b; b = $0; next }
        /^   / { if (b != "") b = b "\001" $0; next }
        { if (b != "") print b; b = ""; exit }
        END { if (b != "") print b }
    ' "$1"
}

check_one() {
    local f="$1" n="$2" size off i out full tmp
    size=$(wc -c < "$f" | tr -d ' ')
    tmp="$(mktemp -d "${TMPDIR:-/tmp}/mcfuzz.XXXXXX")"
    trap 'reap; rm -rf "$tmp"' EXIT
    trap 'exit 143' INT TERM
    local compared=0 frc
    run_limited "$MANTICORE" dump-ast --tolerant "$f" > "$tmp/full.txt" 2>&1
    frc=$?
    blocks "$tmp/full.txt" > "$tmp/full.blocks"
    if [[ $frc -ne 0 || ! -s "$tmp/full.blocks" ]]; then
        echo "FAIL $f: full dump unusable (exit $frc, $(wc -l < "$tmp/full.blocks" | tr -d ' ') statements)"
        echo "STAT $n 0"
        return
    fi
    for ((i = 1; i <= n; i++)); do
        off=$((size * i / (n + 1)))
        head -c "$off" "$f" > "$tmp/cut.php"
        run_limited "$MANTICORE" dump-ast --tolerant "$tmp/cut.php" > "$tmp/cut.txt" 2>&1
        local rc=$?
        if [[ $rc -ne 0 ]]; then echo "FAIL $f @$off: exit $rc"; continue; fi
        run_limited "$MANTICORE" dump-ast --tolerant --check-ranges "$tmp/cut.php" > "$tmp/chk.txt" 2>&1
        if [[ $? -ne 0 || -s "$tmp/chk.txt" ]]; then echo "FAIL $f @$off: ranges: $(head -n 1 "$tmp/chk.txt" | head -c 200)"; fi
        local errs
        errs=$(/usr/bin/grep -c '^error ' "$tmp/cut.txt")
        if [[ $errs -eq 0 ]]; then
            local tail1 lastline
            tail1="$(tail -c 400 "$tmp/cut.php" | tr -d ' \t\r\n' | tail -c 2)"
            lastline="$(/usr/bin/grep -v "^[[:space:]]*$" "$tmp/cut.php" | tail -n 1)"
            local lastclose lastopen after=""
            lastclose=$(/usr/bin/grep -n '?>' "$tmp/cut.php" | tail -n 1 | cut -d: -f1)
            lastopen=$(/usr/bin/grep -n '<?php' "$tmp/cut.php" | tail -n 1 | cut -d: -f1)
            [[ -n "$lastopen" ]] && after="$(tail -n +"$lastopen" "$tmp/cut.php" | sed '1s/.*<?php//' | tr -d ' \t\r\n' | head -c 1)"
            case "$tail1" in
                *';'|*'}'|'?>') ;;
                *) if [[ -n "$after" && "$lastline" != *'//'* && "$lastline" != '#'* && "$lastline" != *'*/'* \
                        && ! ( -n "$lastclose" && ( -z "$lastopen" || $lastclose -ge $lastopen ) ) ]]; then
                       echo "FAIL $f @$off: no error for a mid-construct cut (tail: $(tail -c 40 "$tmp/cut.php" | tr '\n' ' '))"
                   fi ;;
            esac
        fi
        blocks "$tmp/cut.txt" > "$tmp/cut.blocks"
        out=$(LC_ALL=C awk '
            FILENAME == ARGV[1] { full[FNR] = $0; nf = FNR; next }
            { cut[FNR] = $0; nc = FNR }
            END {
                for (i = 1; i <= nc; i++) if (cut[i] ~ /^  Stmt:Error/) { e = i; break }
                last = (e ? e - 1 : nc - 1)
                for (i = 1; i <= last; i++) {
                    if (i > nf || cut[i] != full[i]) { print "DIFF statement " i " differs"; exit }
                }
                print "CMP " (last > 0 ? last : 0)
            }' "$tmp/full.blocks" "$tmp/cut.blocks")
        case "$out" in
            DIFF*) echo "FAIL $f @$off: prefix property: ${out#DIFF }" ;;
            CMP*) compared=$((compared + ${out#CMP })) ;;
            *) echo "FAIL $f @$off: prefix check printed nothing" ;;
        esac
    done
    echo "STAT $n $compared"
}

if [[ "${1:-}" == "--one" ]]; then
    check_one "$2" "$3"
    exit 0
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        -n) CUTS="$2"; shift 2 ;;
        -k) FILTER="$2"; shift 2 ;;
        -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
        *) echo "unknown arg $1" >&2; exit 2 ;;
    esac
done
[[ -x "$MANTICORE" ]] || { echo "fatal: $MANTICORE not built; run bin/build first" >&2; exit 1; }

RES="$(mktemp -d "${TMPDIR:-/tmp}/mcfuzz-run.XXXXXX")"
trap 'reap; pkill -P $$ 2>/dev/null; rm -rf "$RES"' EXIT
trap 'exit 130' INT TERM
fails=0

# ---- 1+2: truncation and prefix property
{
    find src -name '*.php' | sort | awk 'NR % 3 == 0'
    find tests/aot/cases -name '*.php' | sort | awk 'NR % 25 == 0'
} | { if [[ -n "$FILTER" ]]; then /usr/bin/grep -F -- "$FILTER"; else cat; fi; } > "$RES/files"
nfiles=$(wc -l < "$RES/files" | tr -d ' ')
xargs -P 8 -I{} bash "$0" --one {} "$CUTS" < "$RES/files" > "$RES/trunc.out" 2>&1
cuts=$(/usr/bin/grep '^STAT ' "$RES/trunc.out" | awk '{ s += $2 } END { print s + 0 }')
compared=$(/usr/bin/grep '^STAT ' "$RES/trunc.out" | awk '{ s += $3 } END { print s + 0 }')
nstat=$(/usr/bin/grep -c '^STAT ' "$RES/trunc.out")
tfail=$(/usr/bin/grep -c '^FAIL ' "$RES/trunc.out")
echo "truncation+prefix: $nfiles files, $cuts cuts, $compared statements compared, $tfail failures"
/usr/bin/grep '^FAIL ' "$RES/trunc.out" | head -n 30
fails=$((fails + tfail))
if [[ $nstat -ne $nfiles ]]; then echo "FAIL $((nfiles - nstat)) worker(s) died without reporting"; /usr/bin/grep -v '^STAT \|^FAIL ' "$RES/trunc.out" | head -n 5; fails=$((fails + 1)); fi
if [[ $nfiles -gt 0 && $((compared * 100)) -lt $((cuts * 10)) ]]; then echo "FAIL prefix check compared only $compared statements over $cuts cuts"; fails=$((fails + 1)); fi

# ---- 3: garbage (fixed seed)
gfail=0
garbage() {
    local name="$1" f="$RES/g_$1.php"
    cat > "$f"
    run_limited "$MANTICORE" dump-ast --tolerant "$f" > "$RES/g.out" 2>&1
    local rc=$?
    run_limited "$MANTICORE" dump-ast --tolerant --check-ranges "$f" > "$RES/g.chk" 2>&1
    if [[ $rc -ne 0 || $? -ne 0 || -s "$RES/g.chk" ]]; then
        gfail=$((gfail + 1)); echo "FAIL garbage $name: exit $rc $(head -n 1 "$RES/g.chk" | head -c 200)"
        cp "$f" "${TMPDIR:-/tmp}/mcfuzz-fail-$name.php" 2>/dev/null && echo "     kept ${TMPDIR:-/tmp}/mcfuzz-fail-$name.php"
    fi
    rm -f "$f" "$RES/g.out" "$RES/g.chk"
}
for s in $(seq 1 40); do
    garbage "bytes$s" < <(LC_ALL=C awk -v s="$s" 'BEGIN { x = s; printf "<?php\n"; for (i = 0; i < 3000; i++) { x = (x * 48271) % 2147483647; printf "%c", 1 + int(x / 7) % 126 } }')
    garbage "pool$s" < <(LC_ALL=C awk -v s="$s" 'BEGIN {
        x = 1000 + s; n = split("<?php ?> { } ( ) [ ] ; , => -> :: ? : = == . + - * / $a $b foo bar 1 2.5 \"x\" '"'"'y'"'"' if else while for foreach function class fn match new return static public private use namespace try catch finally switch case default echo #[A] @ & | ^ ~ ! ... <<<X \\ ??", t, " ")
        printf "<?php "; for (i = 0; i < 1500; i++) { x = (x * 48271) % 2147483647; printf "%s ", t[1 + int(x / 7) % n] } }')
done
k=0
for src in $(awk 'NR % 20 == 0' "$RES/files" | head -n 20); do
    k=$((k + 1))
    garbage "words$k" < <(LC_ALL=C awk -v s="$k" 'BEGIN { x = 5000 + s } { for (i = 1; i <= NF; i++) w[++n] = $i } END { printf "<?php "; m = (n < 2000 ? n : 2000); for (i = 0; i < m; i++) { x = (x * 48271) % 2147483647; printf "%s ", w[1 + int(x / 7) % n] } }' "$src")
done
echo "garbage: $((80 + k)) inputs, $gfail failures"
fails=$((fails + gfail))

# ---- 4: deep nesting and error flood
rep() { awk -v s="$1" -v n="$2" 'BEGIN { for (i = 0; i < n; i++) printf "%s", s }'; }
dfail=0
{ printf '<?php\n$a = '; rep '(' 10000; } > "$RES/deep.php"
run_limited "$MANTICORE" dump-ast --tolerant "$RES/deep.php" > "$RES/deep.out" 2>&1
rc=$?
if [[ $rc -ne 0 ]] || ! /usr/bin/grep -q 'nesting too deep' "$RES/deep.out"; then dfail=$((dfail + 1)); echo "FAIL deep_parens: exit $rc"; fi
for spec in "parser:foo(;\n:300" "lexer:\001:300" "mixed:foo(;\n\001\n:400"; do
    name="${spec%%:*}"; rest="${spec#*:}"; unit="${rest%:*}"; cnt="${rest##*:}"
    { printf '<?php\n'; rep "$unit" "$cnt"; } > "$RES/flood.php"
    run_limited "$MANTICORE" dump-ast --tolerant "$RES/flood.php" > "$RES/flood.out" 2>&1
    rc=$?
    n=$(/usr/bin/grep -c '^error ' "$RES/flood.out")
    last=$(/usr/bin/grep '^error ' "$RES/flood.out" | tail -n 1)
    if [[ $rc -ne 0 || $n -ne 101 || "$last" != *"too many errors" ]]; then dfail=$((dfail + 1)); echo "FAIL flood_$name: exit $rc, $n errors"; fi
done
echo "deep+flood: $dfail failures"
fails=$((fails + dfail))

echo "---"
if [[ $fails -gt 0 ]]; then echo "fuzz: $fails failure(s)"; exit 1; fi
echo "fuzz: clean"
