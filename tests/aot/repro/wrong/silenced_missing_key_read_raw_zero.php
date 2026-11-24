<?php
// A @-silenced read of a missing key yields the raw 0 word instead of null (int array prints 0, mixed array gives float(0)).
// issue: #810
/** @var array<string, int> $ints */
$ints = ['a' => 1];
/** @var array<string, mixed> $mixed */
$mixed = ['a' => 'x', 'b' => 2];
$k = $argc > 5 ? 'a' : 'nope';
var_dump(@$ints[$k]);
var_dump(@$mixed[$k]);
echo '[' . @$ints[$k] . "]\n";
