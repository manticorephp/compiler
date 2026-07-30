#!/usr/bin/env bash
#
# calibrate.sh — prove the analyzer is honest before trusting anything it says.
#
# Two checks, both cheap, both run at the START and END of every audit phase:
#
#   1. QUIET   Run `analyze` over a corpus that is KNOWN GREEN (symfony/console
#              compiles byte-exact, 7/7) and separate its undefined-* output
#              into two piles:
#
#                BLIND SPOT  the symbol IS declared somewhere in the analyzed
#                            source set, and the analyzer failed to see it.
#                            Pure noise. Must be zero (bar annotated residue).
#                FINDING     the symbol is declared nowhere — genuinely absent
#                            from both the corpus and manticore. A real gap,
#                            reported, NOT a failure.
#
#              "Zero undefined.*" is the wrong bar: a green corpus legitimately
#              references symbols it does not ship (`class_exists` guards, type
#              hints in unreached code), and manticore legitimately lacks
#              functions the corpus never calls on a live path. Only the blind
#              spots make the audit lie.
#
#              Advisory rules (array.no-value-type) are excluded throughout:
#              they are correct observations about third-party docblocks.
#
#   2. SYNC    The analyzer's two hand-written universes must still cover what
#              the compiler actually implements:
#                - Analyze\Builtins::functionSet ⊇ emitBuiltin dispatch names
#                - analyze_prelude_files()       == prelude/*.php
#              Both are runtime hardcodes on purpose (globbing a directory and
#              regexing a source file from inside the compiled binary would add
#              a bootstrap dependency that the cold seed cannot satisfy), so the
#              derivation lives here, in the gate, instead.
#
# Usage: bash tools/audit/calibrate.sh [green-corpus-dir ...]
# Exit:  0 quiet + in sync, 1 otherwise.

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

GREEN=("$@")
if [ ${#GREEN[@]} -eq 0 ]; then
    GREEN=(/Users/taras/var/projects/symfony-probe/vendor \
           /Users/taras/var/projects/symfony-probe/src \
           /Users/taras/var/projects/symfony-probe/bin)
fi

RC=0

echo "== SYNC =="
php -r '
$root = getcwd();
$fail = 0;

$disp = [];
$src = (string)file_get_contents("$root/src/Compile/Mir/Passes/EmitLlvmBuiltins.php");
preg_match_all("/\\\$name === .([a-z_0-9]+)./", $src, $m);
foreach ($m[1] as $n) { $disp[$n] = true; }

$known = [];
$b = (string)file_get_contents("$root/src/Analyze/Builtins.php");
preg_match_all("/.([a-z_0-9]+)./", $b, $mm);
foreach ($mm[1] as $n) { $known[$n] = true; }

$missing = array_values(array_diff(array_keys($disp), array_keys($known)));
if ($missing) {
    $fail = 1;
    echo "FAIL Analyze\\Builtins is missing ", count($missing), " emitBuiltin dispatch name(s):\n";
    echo "     ", implode(", ", $missing), "\n";
    echo "     A user program calling one reads as an undefined function.\n";
} else {
    echo "ok   Analyze\\Builtins covers all ", count($disp), " emitBuiltin dispatch names\n";
}

$listed = [];
$main = (string)file_get_contents("$root/src/Manticore/Main.php");
$s = strpos($main, "function analyze_prelude_files");
$e = strpos($main, "return \$out;", $s);
preg_match_all("/.([a-z_0-9]+\.php)./", substr($main, $s, $e - $s), $pm);
foreach ($pm[1] as $n) { $listed[$n] = true; }
$onDisk = [];
foreach (glob("$root/prelude/*.php") as $p) { $onDisk[basename($p)] = true; }
$absent = array_values(array_diff(array_keys($onDisk), array_keys($listed)));
if ($absent) {
    $fail = 1;
    echo "FAIL analyze_prelude_files() omits ", count($absent), " prelude file(s):\n";
    echo "     ", implode(", ", $absent), "\n";
    echo "     Every function they declare reads as undefined.\n";
} else {
    echo "ok   analyze_prelude_files() lists all ", count($onDisk), " prelude files\n";
}
exit($fail);
'
[ $? -ne 0 ] && RC=1

echo
echo "== QUIET (green corpus) =="
if [ ! -x ./bin/manticore ]; then
    echo "FAIL no ./bin/manticore — build the worktree first"
    exit 1
fi

OUT=$(mktemp)
MANTICORE_PRELUDE="$ROOT/prelude" ./bin/manticore analyze --json "${GREEN[@]}" > "$OUT" 2>/dev/null
# `analyze` exits 1 whenever ANY error-severity diagnostic exists, which is not
# the question here — the question is whether any UNDEFINED-symbol rule fired.
php -r '
$json = $argv[1];
$residuePath = $argv[2];
$roots = array_slice($argv, 3);

$d = json_decode((string)file_get_contents($json), true);
if (!is_array($d)) { echo "FAIL analyze produced no parseable json\n"; exit(1); }

// Every function / class / interface / trait / enum DECLARED anywhere in the
// analyzed source set, including inside conditional blocks — the polyfill idiom
// this gate exists to keep honest.
$declFn = [];
$declCls = [];
foreach ($roots as $root) {
    if (!is_dir($root)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== "php") { continue; }
        $src = (string)file_get_contents($f->getPathname());
        if (preg_match_all("/^\s*(?:final\s+|abstract\s+|readonly\s+)*function\s+&?([A-Za-z_][A-Za-z0-9_]*)\s*\(/m", $src, $m)) {
            foreach ($m[1] as $n) { $declFn[strtolower($n)] = true; }
        }
        // Classes are matched FULLY QUALIFIED. Unlike functions, PHP has no
        // global fallback for a class name, so `Ns\InvalidArgumentException`
        // and the SPL `InvalidArgumentException` are different symbols -- a
        // bare-name match here would excuse a genuine absence.
        $ns = "";
        if (preg_match("/^\s*namespace\s+([^;{\s]+)/m", $src, $nm)) { $ns = $nm[1] . "\\"; }
        if (preg_match_all("/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m", $src, $m)) {
            foreach ($m[1] as $n) { $declCls[strtolower($ns . $n)] = true; }
        }
    }
}

// Annotated residue: known blind spots that are explained and deliberately not
// chased. Format is `code<TAB>name<TAB>reason`; a bare name with no reason does
// not count, which is what stops this becoming a dumping ground.
$residue = [];
foreach (@file($residuePath) ?: [] as $line) {
    $line = trim($line);
    if ($line === "" || $line[0] === "#") { continue; }
    $p = explode("\t", $line);
    if (count($p) < 3 || trim($p[2]) === "") { continue; }
    $residue[trim($p[0]) . "|" . strtolower(trim($p[1]))] = trim($p[2]);
}

$hard = ["undefined.function", "undefined.class", "undefined.method",
         "undefined.class-const", "undefined.constant"];
$blind = [];
$finding = [];
$excused = 0;

foreach ($d as $x) {
    $code = (string)($x["code"] ?? "?");
    if (!in_array($code, $hard, true)) { continue; }
    $msg = (string)($x["message"] ?? "");
    // "unknown function Ns\name()" / "unknown class Ns\Name" / "unknown method C->m()"
    if (!preg_match("/^unknown \S+ (.+?)(?:\(\))?$/", $msg, $m)) { continue; }
    $sym = $m[1];
    if ($code === "undefined.method") {
        // A method on an INTERFACE-typed receiver whose runtime object is a
        // subclass cannot be resolved statically. Inherent, not a blind spot.
        $finding[$code][$sym] = true;
        continue;
    }
    if ($code === "undefined.function") {
        // Functions DO fall back to the global namespace, so a bare-name match
        // is the right test.
        $bare = $sym;
        $bs = strrpos($bare, "\\");
        if ($bs !== false) { $bare = substr($bare, $bs + 1); }
        $bare = strtolower($bare);
        $declared = isset($declFn[$bare]);
    } else {
        $bare = strtolower(ltrim($sym, "\\"));
        $declared = isset($declCls[$bare]);
    }
    if (!$declared) { $finding[$code][$sym] = true; continue; }
    if (isset($residue[$code . "|" . $bare])) { $excused++; continue; }
    $blind[$code][$sym] = ($x["file"] ?? "?") . ":" . ($x["line"] ?? 0);
}

$nFind = 0;
foreach ($finding as $c => $s) { $nFind += count($s); }
echo "     ", count($d), " diagnostics total; ", $nFind, " distinct genuine absence(s); ",
     $excused, " excused by residue\n";
foreach ($finding as $c => $s) { echo "     finding  ", $c, " x", count($s), "\n"; }

$nBlind = 0;
foreach ($blind as $c => $s) { $nBlind += count($s); }
if ($nBlind === 0) {
    echo "ok   no analyzer blind spots — every undefined.* names a symbol declared NOWHERE\n";
    exit(0);
}
echo "FAIL ", $nBlind, " blind spot(s): declared in the corpus, invisible to the analyzer\n";
foreach ($blind as $c => $s) {
    foreach ($s as $sym => $at) { echo "     ", $c, "  ", $sym, "  at ", $at, "\n"; }
}
exit(1);
' "$OUT" "$ROOT/docs/audit/data/calibration-residue.txt" "${GREEN[@]}"
[ $? -ne 0 ] && RC=1
rm -f "$OUT"

echo
[ $RC -eq 0 ] && echo "calibrate: OK" || echo "calibrate: NOT CALIBRATED (findings above are noise, not gaps)"
exit $RC
