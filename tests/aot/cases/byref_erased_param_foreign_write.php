<?php
// A value COMPUTED from the by-ref array is no element of it: `$a['q'] =
// count($a)` writes an int into the caller's string buffer, so the caller's
// local has to be a mixed array. Counting it as a move left raw ints in a
// string-element buffer (SIGSEGV, or addresses printed as ints).
/** @param array $a */
function f(array &$a): void { $a['q'] = count($a); }
$x = ['a' => 'x', 'b' => 'y'];
f($x); var_dump($x);

$g = function (array &$a): void { $a['q'] = count($a); $a[] = strlen('abc') * 1.5; };
$y = ['a' => 'x', 'b' => 'y'];
$g($y); var_dump($y);
$v = ['s1', 's2'];
$g($v); var_dump($v);
