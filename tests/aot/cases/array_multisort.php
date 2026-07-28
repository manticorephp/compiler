<?php

// array_multisort is DESUGARED at the call site (LowerExprs::lowerMultisort):
// its array arguments are BY REF and the SORT_* settings are interleaved
// positionally, which would need a by-ref variadic pack. The desugar computes
// the row permutation once, then rewrites each column through a by-ref
// __mc_multisort_apply so every column keeps its own element repr.

// Two parallel columns: the second follows the first's ordering.
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, $b);
print_r($a);
print_r($b);

// Single column, descending.
$c = [3, 1, 2];
array_multisort($c, SORT_DESC);
print_r($c);

// String keys are kept, numeric keys re-indexed.
$d = ['x' => 3, 'y' => 1, 5 => 2];
array_multisort($d);
print_r($d);

// Ties in column 1 are broken by column 2.
$e = [1, 1, 0];
$f = ['b', 'a', 'z'];
array_multisort($e, $f);
print_r($e);
print_r($f);

// Per-column order: ascending then descending.
$g = [1, 1, 0];
$h = ['a', 'b', 'z'];
array_multisort($g, SORT_ASC, $h, SORT_DESC);
print_r($g);
print_r($h);

// SORT_STRING vs SORT_NUMERIC over numeric strings.
$i = ['10', '9', '1'];
array_multisort($i, SORT_STRING);
print_r($i);
$j = ['10', '9', '1'];
array_multisort($j, SORT_NUMERIC);
print_r($j);

// Order and flags in either sequence.
$k = ['10', '9', '1'];
array_multisort($k, SORT_DESC, SORT_STRING);
print_r($k);
$l = ['10', '9', '1'];
array_multisort($l, SORT_STRING, SORT_DESC);
print_r($l);

// SORT_NATURAL.
$m = ['img12', 'img10', 'img2'];
array_multisort($m, SORT_NATURAL);
print_r($m);

// The return value is always true.
$n = [2, 1];
var_dump(array_multisort($n));

// Empty and single-element columns.
$o = [];
array_multisort($o);
print_r($o);
$p = [42];
array_multisort($p);
print_r($p);

// Three columns.
$q = [1, 1, 1];
$r = [2, 2, 1];
$s = ['x', 'y', 'z'];
array_multisort($q, $r, $s);
print_r($q);
print_r($r);
print_r($s);

// Inconsistent sizes.
try {
    $t = [1, 2];
    $u = [1];
    array_multisort($t, $u);
} catch (\Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
}
