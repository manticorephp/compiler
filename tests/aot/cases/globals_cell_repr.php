<?php
// `$GLOBALS['x']` READS as a cell (a self-describing slot), so the WRITE has to
// box. Storing the raw word made every scalar come back as the double with those
// bits: `$GLOBALS['g'] = 7` read as 3.5E-323.
//
// ⛔ Two shapes are still open and deliberately not asserted here:
//   · an ARRAY in the slot — arrays ride RAW in a cell channel by design
//     (boxing would rebuild the array and change its identity), so
//     `$GLOBALS['a'] = ['k'=>1]` reads back as a float. That is the
//     cell-array-backing-slot half of the repr-consistency epic.
// The two VIEWS of one slot now agree — `global $x`, the top-level variable and
// `$GLOBALS['x']` box and unbox the same way (asserted below).
function show(mixed $v): string { return gettype($v) . ":" . var_export($v, true); }

$GLOBALS['gi'] = 7;
$GLOBALS['gs'] = "text";
$GLOBALS['gf'] = 1.25;
$GLOBALS['gb'] = true;
$GLOBALS['gn'] = null;

echo show($GLOBALS['gi']), "\n";
echo show($GLOBALS['gs']), "\n";
echo show($GLOBALS['gf']), "\n";
echo show($GLOBALS['gb']), "\n";
echo show($GLOBALS['gn']), "\n";

// Arithmetic, concatenation and identity straight off the slot.
$GLOBALS['n'] = 10;
echo $GLOBALS['n'] + 5, "\n";
echo $GLOBALS['gs'] . "!", "\n";
var_dump($GLOBALS['n'] === 10);
var_dump($GLOBALS['gs'] === "text");
var_dump($GLOBALS['gb'] === true);
var_dump($GLOBALS['gn'] === null);

// Overwrite with a different carrier — the slot is self-describing, so the new
// tag is what the next read sees.
$GLOBALS['n'] = "now a string";
echo show($GLOBALS['n']), "\n";

// One slot, one representation: written through any view, read through any other.
$counter = 7;
function bump(): int { $GLOBALS['counter'] = $GLOBALS['counter'] + 1; return $GLOBALS['counter']; }
echo bump(), " ", bump(), " ", $counter, "\n";
$GLOBALS['shared'] = 41;
function bump2(): void { global $shared; $shared = $shared + 1; }
bump2();
echo $GLOBALS['shared'], " ", $shared, "\n";
