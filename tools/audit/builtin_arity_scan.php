<?php

/**
 * Codegen-builtin ARITY divergence scan.
 *
 * A codegen builtin is emitted inline and has no signature of its own, so
 * nothing checks that it accepts as many arguments as the php function whose
 * name it answers to. When it accepts fewer, the extra arguments are dropped
 * IN SILENCE — the worst class of answer:
 *
 *     $a = [3, 4]; array_unshift($a, 1, 2);
 *     php:       4: 1,2,3,4
 *     manticore: 3: 1,3,4
 *
 * That one was found by accident, when the symfony tier-4 build tripped over
 * the SPREAD form (which refuses loudly) and the multi-value form turned out to
 * have been quietly wrong all along. This scan looks for the rest on purpose.
 *
 * Method — two sides, both static:
 *
 *   php's side   ReflectionFunction: parameter count, how many are required,
 *                and whether the tail is variadic.
 *   our side     the highest `args[N]` index the emitter method actually reads,
 *                plus whether it LOOPS over the argument list (a builtin that
 *                iterates is variadic-aware and is not reported).
 *
 * The emitter side is a heuristic and says so: it reads PHP source, not
 * behaviour. It is deliberately biased toward FALSE POSITIVES — a builtin that
 * reaches its arguments in a way this does not model is reported and dismissed
 * by hand, which is cheap. A false NEGATIVE is what this exists to prevent.
 *
 * ── TRIAGE, 2026-08-09: 177 scanned, 15 reported, 6 probed against the oracle ──
 *
 * CONFIRMED — each reproduces with a two-line program:
 *   json_encode($v, JSON_PRETTY_PRINT)          SIGSEGV at runtime (not merely
 *                                               a dropped flag — worse than a
 *                                               wrong answer)
 *   print_r($v, true)                           PRINTS instead of returning the
 *                                               string; the caller gets no value
 *   is_a('ArrayObject','Traversable',true)      answers n, php answers y (the
 *                                               allow_string third argument)
 *
 * FALSE POSITIVE — the argument is honoured somewhere this scan cannot see
 * (a desugar, a stdlib body, or a separate dispatch):
 *   count($a, COUNT_RECURSIVE)                  correct (fixed in 10221a4)
 *   json_decode($s, true)                       correct
 *   class_exists('Nope', false)                 correct
 *
 * NOT YET PROBED: array_unshift (confirmed separately — drops every value past
 * the second), debug_backtrace, enum_exists, error_log, getenv, interface_exists,
 * is_callable, is_subclass_of, trait_exists.
 *
 * Usage:  php tools/audit/builtin_arity_scan.php [--all]
 *   default   only DIVERGENT rows (what to fix)
 *   --all     every builtin, including the ones that agree
 *
 * Exit code is 0 always: this is a report, not a gate. Making it a gate means
 * first triaging the known-benign rows into an allowlist.
 */

$root = \dirname(__DIR__, 2);
$src = $root . '/src/Compile/Mir/Passes/EmitLlvmBuiltins.php';
if (!\is_file($src)) {
    \fwrite(\STDERR, "not found: $src\n");
    exit(2);
}
$showAll = \in_array('--all', $argv, true);
$text = \file_get_contents($src);
$lines = \explode("\n", $text);

// ── 1. the dispatch table: php name → emitter method ───────────────────────
// Lines look like:
//   if ($name === 'array_unshift')  { return $this->biArrayUnshift($c); }
$dispatch = [];
foreach ($lines as $i => $l) {
    if (!\preg_match("/\\\$name === '([A-Za-z_][A-Za-z0-9_]*)'/", $l, $m)) {
        continue;
    }
    if (!\preg_match('/\$this->(bi[A-Za-z0-9_]+)\s*\(/', $l, $m2)) {
        continue;
    }
    $dispatch[$m[1]] = ['method' => $m2[1], 'line' => $i + 1];
}

// ── 2. each emitter method's body, and the highest argument index it reads ──
/** @return array{0:int,1:bool} max index read (-1 = none), whether it loops */
function methodArgFacts(array $lines, string $method): array
{
    $start = -1;
    foreach ($lines as $i => $l) {
        if (\strpos($l, 'function ' . $method . '(') !== false) { $start = $i; break; }
    }
    if ($start < 0) { return [-1, false]; }
    // Body ends at the first line that closes the method at its own indent.
    $end = \count($lines);
    for ($j = $start + 1; $j < \count($lines); $j++) {
        if (\preg_match('/^    \}/', $lines[$j])) { $end = $j; break; }
    }
    $max = -1;
    $loops = false;
    for ($j = $start; $j < $end; $j++) {
        $l = $lines[$j];
        // `$c->args[3]` / `$args[3]` — a literal index
        if (\preg_match_all('/(?:->)?args\[(\d+)\]/', $l, $mm)) {
            foreach ($mm[1] as $n) { if ((int)$n > $max) { $max = (int)$n; } }
        }
        // A variable index, a foreach over the list, or a count of it: the
        // builtin is reaching arguments it does not name one by one.
        if (\preg_match('/foreach\s*\(\s*\$(?:c->)?args\b/', $l)) { $loops = true; }
        if (\preg_match('/(?:->)?args\[\s*\$/', $l)) { $loops = true; }
        if (\preg_match('/\\\\?count\(\s*\$(?:c->)?args\s*\)/', $l)) { $loops = true; }
        if (\preg_match('/\barray_slice\(\s*\$(?:c->)?args/', $l)) { $loops = true; }
    }
    return [$max, $loops];
}

// ── 3. compare against Zend ────────────────────────────────────────────────
$rows = [];
foreach ($dispatch as $name => $info) {
    [$maxIdx, $loops] = methodArgFacts($lines, $info['method']);
    $ours = $maxIdx + 1;                       // arguments the emitter names

    if (!\function_exists($name)) {
        $rows[] = [$name, $info, $ours, $loops, 'NOT-IN-ZEND', '', 'superset or internal name'];
        continue;
    }
    try {
        $rf = new \ReflectionFunction($name);
    } catch (\Throwable $e) {
        continue;
    }
    $zTotal = $rf->getNumberOfParameters();
    $zReq = $rf->getNumberOfRequiredParameters();
    $zVariadic = false;
    foreach ($rf->getParameters() as $p) {
        if ($p->isVariadic()) { $zVariadic = true; }
    }

    $verdict = 'ok';
    $why = '';
    if ($loops) {
        $verdict = 'ok';
        $why = 'emitter iterates the argument list';
    } elseif ($zVariadic && $ours < $zTotal + 1) {
        $verdict = 'DIVERGENT';
        $why = 'php is VARIADIC; every argument past ' . (string)$ours . ' is dropped';
    } elseif ($ours < $zTotal) {
        // Optional php parameters the emitter never reads are dropped too.
        $verdict = 'DIVERGENT';
        $why = 'php takes up to ' . (string)$zTotal . ' (required ' . (string)$zReq
             . '); emitter reads ' . (string)$ours;
    }
    $rows[] = [$name, $info, $ours, $loops, $verdict, $zVariadic ? 'variadic' : (string)$zTotal, $why];
}

// ── 4. report ──────────────────────────────────────────────────────────────
\usort($rows, function ($a, $b) {
    $rank = ['DIVERGENT' => 0, 'NOT-IN-ZEND' => 1, 'ok' => 2];
    $d = ($rank[$a[4]] ?? 3) <=> ($rank[$b[4]] ?? 3);
    return $d !== 0 ? $d : \strcmp($a[0], $b[0]);
});

$divergent = 0;
echo "builtin                        emitter  zend       verdict      why\n";
echo "-----------------------------  -------  ---------  -----------  ---------------------------------\n";
foreach ($rows as $r) {
    [$name, $info, $ours, $loops, $verdict, $zend, $why] = $r;
    if ($verdict === 'DIVERGENT') { $divergent++; }
    if (!$showAll && $verdict !== 'DIVERGENT') { continue; }
    echo \str_pad($name, 30), ' ', \str_pad((string)$ours, 8), ' ',
         \str_pad((string)$zend, 10), ' ', \str_pad($verdict, 12), ' ', $why, "\n";
    if ($verdict === 'DIVERGENT') {
        echo \str_repeat(' ', 30), " EmitLlvmBuiltins.php:", $info['line'],
             '  ', $info['method'], "()\n";
    }
}
echo "\n", (string)\count($rows), " builtin(s) scanned, ", (string)$divergent, " divergent.\n";
echo "The emitter side is a SOURCE heuristic — dismiss a false positive by hand,\n";
echo "and prefer that to the silent wrong answer a false negative hides.\n";
