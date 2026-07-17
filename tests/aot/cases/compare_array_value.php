<?php
// PHP compares arrays BY VALUE, recursively: `==` ignores key order, `===`
// demands the same pairs in the same order, ordering goes by count first.
$a = [1, 2, 3];
$b = [1, 2, 3];
$c = [1, 2, 4];
var_dump($a == $b, $a === $b, $a == $c, $a === $c, $a != $c, $a !== $c);
var_dump($a <=> $b, $a <=> $c, $c <=> $a);

// Key ORDER is irrelevant to `==` but not to `===`.
$e = ['x' => 1, 'y' => 2];
$f = ['y' => 2, 'x' => 1];
var_dump($e == $f, $e === $f);

// `==` juggles the values, `===` does not.
$g = [1, '2', 3];
var_dump($a == $g, $a === $g);

// Fewer members is smaller.
var_dump([1, 2] < [1, 2, 3], [1, 2, 3] > [1, 2], [1] <= [1], [1] >= [1]);
var_dump([] == [], [] === []);

// Nested arrays compare at every level (raw inner elements, not cells).
$n = [[1, 2], [3]];
$m = [[1, 2], [3]];
var_dump($n == $m, $n === $m);
var_dump([[1, 2], [3]] == [[1, 2], [4]]);

// The comparison-driven builtins ride the same engine.
var_dump(in_array([1, 2], [[1, 2], [3]]));
var_dump(in_array([1, 2], [[1, 2], [3]], true));
var_dump(array_search([3], [[1, 2], [3]]));
$arrs = [[3, 1], [1, 2], [1, 1]];
usort($arrs, fn($x, $y) => $x <=> $y);
print_r($arrs);
$srt = [[2], [1]];
sort($srt);
print_r($srt);
