<?php

// The u* comparator family. Two things it pins beyond the functions themselves:
// a string-name comparator resolves through Monomorphize's callable dimension,
// and a MIXED-key array hands its keys to the comparator as CELLS — an int key
// passed to `strcasecmp(string, string)` used to arrive as the bare payload
// (0 for key 0, i.e. a NULL pointer the callee dereferenced).

function cmpv($a, $b) { return $a <=> $b; }

$a = ['a' => 'green', 'b' => 'brown', 'c' => 'blue', 'red'];
$b = ['a' => 'GREEN', 'B' => 'brown', 'yellow', 'red'];

print_r(array_udiff($a, $b, 'strcasecmp'));
print_r(array_uintersect($a, $b, 'strcasecmp'));
print_r(array_udiff_assoc($a, $b, 'strcasecmp'));
print_r(array_uintersect_assoc($a, $b, 'strcasecmp'));
print_r(array_diff_uassoc($a, $b, 'strcasecmp'));
print_r(array_intersect_uassoc($a, $b, 'strcasecmp'));
print_r(array_udiff_uassoc($a, $b, 'strcasecmp', 'strcasecmp'));
print_r(array_uintersect_uassoc($a, $b, 'strcasecmp', 'strcasecmp'));

// A closure comparator and a named user function take the same path.
$n1 = [1, 5, 3, 9];
$n2 = [3, 9, 12];
print_r(array_udiff($n1, $n2, fn($x, $y) => $x <=> $y));
print_r(array_uintersect($n1, $n2, fn($x, $y) => $x <=> $y));
print_r(array_udiff($n1, $n2, 'cmpv'));
print_r(array_uintersect($n1, $n2, 'cmpv'));

// Empty operands.
print_r(array_udiff([], $n2, 'cmpv'));
print_r(array_uintersect($n1, [], 'cmpv'));
