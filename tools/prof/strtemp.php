<?php
/**
 * A STRING TEMP that nobody owns. `(string)$int` and `(string)$float` allocate
 * (`__mir_int_to_str` / `__mir_float_to_str` mint a fresh rc=1 buffer), but the
 * cast was in neither ownership predicate — not
 * {@see \Compile\Mir\Passes\InsertMemoryOps::isOwnedObj}, which schedules the
 * local's release and the release-before-overwrite, and not
 * {@see \Compile\Mir\Passes\EmitLlvm::isFreshStringTemp}, which frees a fresh
 * argument after the callee has read it. So every one of these leaked one
 * string per iteration where php is flat.
 *
 *   bin/manticore compile tools/prof/strtemp.php -o /tmp/strtemp
 *   for m in cast castarg interp key argcall nested store; do
 *     /usr/bin/time -l /tmp/strtemp $m 1000000 2>&1 | grep 'maximum resident'
 *   done
 *
 * All seven are flat (~1 MB) once both predicates know the cast; php 8.5 sits
 * at ~28 MB for every mode.
 */

function st_run(string $mode, int $n): int
{
    $sink = 0;
    $sv = 'abc';
    $f = 1.5;
    /** @var array<string,int> $m */
    $m = [];
    for ($i = 0; $i < $n; $i++) {
        if ($mode === 'cast') {              // stored in a local, rebound each turn
            $s = (string)$i;
            $sink += \strlen($s);
        } elseif ($mode === 'castarg') {     // fresh temp straight into a callee
            $sink += \strlen((string)$i);
        } elseif ($mode === 'interp') {
            $s = "x$i";
            $sink += \strlen($s);
        } elseif ($mode === 'key') {         // the KEY temp of an element store
            $m[(string)$i] = 1;
            $sink += \count($m);
            unset($m[(string)$i]);
        } elseif ($mode === 'argcall') {     // f(g(temp))
            $sink += \strlen(\strrev((string)$i));
        } elseif ($mode === 'floatcast') {
            $s = (string)$f;
            $sink += \strlen($s);
        } elseif ($mode === 'store') {       // a non-cast producer, the control
            $s = \str_repeat('a', 3);
            $sink += \strlen($s);
        }
    }
    return $sink;
}

$argvv = $argv;
$mode = $argvv[1] ?? 'cast';
$iters = (int)($argvv[2] ?? 1000000);
echo $mode, ' ', st_run($mode, $iters), "\n";
