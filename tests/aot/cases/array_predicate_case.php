<?php
// PHP 8.4 predicate helpers and array_change_key_case, implemented in the
// injected prelude so callbacks stay in the compiled module.
$values = [10 => 0, 'first' => 3, 'last' => 8];

echo array_all($values, fn($v) => $v >= 0) ? "all\n" : "not-all\n";
echo array_any($values, fn($v) => $v === 3) ? "any\n" : "not-any\n";
echo array_any([], fn($v) => true) ? "bad\n" : "empty-any\n";
echo array_all([], fn($v) => false) ? "empty-all\n" : "bad\n";

$found = array_find($values, fn($v) => $v === 0);
echo $found === 0 ? "zero\n" : "bad\n";
echo array_find_key($values, fn($v) => $v === 3), "\n";
echo array_find($values, fn($v) => $v === 99) === null ? "no-value\n" : "bad\n";
echo array_find_key($values, fn($v) => $v === 99) === null ? "no-key\n" : "bad\n";

$lower = array_change_key_case(['Name' => 1, 'TWO' => 2, 7 => 3]);
$upper = array_change_key_case(['Name' => 1, 'two' => 2, 7 => 3], 1);
echo array_key_exists('name', $lower) && array_key_exists('two', $lower) ? "lower\n" : "bad\n";
echo $lower['name'] + $lower['two'] + $lower[7], "\n";
echo array_key_exists('NAME', $upper) && array_key_exists('TWO', $upper) ? "upper\n" : "bad\n";
echo $upper['NAME'] + $upper['TWO'] + $upper[7], "\n";
