<?php

// array_splice rebuilds the array through a by-ref param: INT keys reindex on
// both sides, STRING keys survive. The rebuild-and-assign-back shape is what
// scanByRefElemWiden's whole-array branch exists for — the buffer handed in is
// vec[int] while the one assigned back carries the replacement's elements.

$a = ['x' => 1, 'y' => 2, 3, 4];
$r = array_splice($a, 1, 2, ['A', 'B', 'C']);
print_r($a);
print_r($r);

$b = [1, 2, 3, 4, 5];
print_r(array_splice($b, -2));
print_r($b);

// A scalar replacement counts as a one-element list; length 0 inserts.
$c = [1, 2, 3];
print_r(array_splice($c, 1, 0, 'ins'));
print_r($c);

// A negative length stops that many entries from the end.
$d = [1, 2, 3, 4];
print_r(array_splice($d, 1, -1));
print_r($d);

// The replacement may be longer than the removed slice.
$e = ['a', 'b', 'c'];
print_r(array_splice($e, 0, 2, [10, 20, 30]));
print_r($e);

// Splicing into an empty array is a plain append.
$f = [];
var_dump(array_splice($f, 0, 0, ['only']));
print_r($f);

// Offsets past the end clamp.
$g = [1, 2];
print_r(array_splice($g, 9, 4, ['tail']));
print_r($g);
