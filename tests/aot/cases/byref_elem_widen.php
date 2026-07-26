<?php

// A by-ref array param is narrowed to the caller's element type (that is what
// keeps the sorts fast), so a callee that APPENDS a different type wrote a raw
// string pointer into an int-repr buffer. InferScans::scanByRefElemWiden widens
// the CALLER's local to a cell element instead — the one place both sides agree.

function push_str(array &$arr): void { $arr[] = 'tail'; }
$a = [1, 2, 3];
push_str($a);
print_r($a);

// The assoc half: `['a' => 1]` is an untouched all-string-key literal, i.e. a
// RECORD, until a by-ref callee writes a field the record has no slot repr for.
function tag(array &$m): void { $m['note'] = 'hi'; }
$b = ['a' => 1];
tag($b);
print_r($b);

// A callee that only MOVES elements must NOT widen anything — this is the sort
// family's shape (a merge buffer filled out of the array, then written back).
function swap01(array &$a): void { $x = $a[0]; $a[0] = $a[1]; $a[1] = $x; }
$s = [7, 8];
swap01($s);
print_r($s);

$ints = [5, 3, 9, 1];
sort($ints);
print_r($ints);
$strs = ['pear', 'apple', 'fig'];
usort($strs, fn($x, $y) => strcmp($x, $y));
print_r($strs);
$k = ['b' => 2, 'a' => 1];
ksort($k);
print_r($k);

// A variadic pack is a per-call-site decision: array_push appends whatever the
// caller passed, so only the arguments say whether it fits the buffer.
$p = [1, 2];
array_push($p, 3);
print_r($p);
$q = [1, 2];
array_push($q, 'three', 4.5);
print_r($q);

// Foreign float into an int vec.
function push_f(array &$a): void { $a[] = 1.5; }
$f = [1, 2];
push_f($f);
print_r($f);

// Transitive hand-off: `outer` appends whatever `inner` appends.
function inner(array &$a): void { $a[] = 'deep'; }
function outer(array &$a): void { inner($a); }
$t = [1, 2];
outer($t);
print_r($t);

// A foreign store behind a branch still widens — the taken path is not knowable.
function maybe(array &$a, bool $yes): void { if ($yes) { $a['k'] = 'v'; } }
$g = ['n' => 1];
maybe($g, true);
print_r($g);
$h = ['n' => 1];
maybe($h, false);
print_r($h);
echo count($h), "\n";
