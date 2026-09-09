<?php

// An array element holding a REFERENCE, read by the WALKERS rather than by
// subscript. `$e[0]` (the keyed read) has always deref'd; foreach, implode and
// everything else that iterates read the element word through a different path
// and handed back the BOX ADDRESS instead of the value.

$d = 7;
$e = [&$d, 11];

// The keyed read and the write-through — the shapes that already worked.
var_dump($e[0]);
$e[0] = 8;
var_dump($d);
$d = 7;

// foreach: the value is what the reference REFERS TO, never the binding.
foreach ($e as $v) { echo $v, ' '; }
echo "\n";
foreach ($e as $k => $v) { echo $k, '=', $v, ' '; }
echo "\n";

// Every other full iteration goes through the same seam.
echo implode(',', $e), "\n";
echo count($e), "\n";
print_r($e);
echo array_sum($e), "\n";
print_r(array_values($e));
print_r(array_map(fn ($x) => $x * 2, $e));
print_r(array_filter($e, fn ($x) => $x > 8));

// A spread reads elements too.
function three($a, $b) { return $a + $b; }
echo three(...$e), "\n";

// The reference still writes through after all that walking.
$e[0] = 42;
var_dump($d);
$d = 5;
var_dump($e[0]);
foreach ($e as $v) { echo $v, ' '; }
echo "\n";

// A reference to a STRING element, so the deref is not int-shaped only.
$s = 'ab';
$f = [&$s, 'cd'];
echo implode('|', $f), "\n";
foreach ($f as $v) { echo strtoupper($v), ' '; }
echo "\n";
$s = 'zz';
echo implode('|', $f), "\n";
