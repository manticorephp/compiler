<?php
// A row that is neither an array nor an object has no columns and is skipped.
$a = ['a' => 1, 'b' => ['x', 'y'], 'c' => str_repeat('q', 40), 'd' => null, 'e' => 2.5, 'f' => true];
var_dump(array_column($a, 'b'));
var_dump(array_column([1, 'two', null, ['k' => 'v'], (object) ['k' => 'o']], 'k'));
var_dump(array_column($a, null));
