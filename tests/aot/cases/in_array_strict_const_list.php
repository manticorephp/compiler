<?php
declare(strict_types=1);

function ints(?int $n): string { return in_array($n, [3, 7, 7, -1, 40], true) ? 'y' : 'n'; }
function mixedNeedle(mixed $n): string { return in_array($n, [1, 2, 3], true) ? 'y' : 'n'; }
function strs(string $s): string { return in_array($s, ['+', '-', '&', ''], true) ? 'y' : 'n'; }
function loose(mixed $n): string { return in_array($n, [1, 2, 3]) ? 'y' : 'n'; }
function mixedList(mixed $n): string { return in_array($n, [1, 'a', 3], true) ? 'y' : 'n'; }
function intNeedle(int $n): string { return in_array($n, [10, 20, 30], true) ? 'y' : 'n'; }

foreach ([3, 7, -1, 40, 4, 0, null] as $v) { echo ints($v); }
echo "\n";
foreach ([1, 2, 3, 4, 1.0, '1', true, null, [1], 0, '2'] as $v) { echo mixedNeedle($v); }
echo "\n";
foreach (['+', '-', '&', '', '*', '--', ' +'] as $v) { echo strs($v); }
echo "\n";
foreach ([1, '1', 1.0, true, 4, null, 'x'] as $v) { echo loose($v); }
echo "\n";
foreach ([1, 'a', 3, 'b', 2, null] as $v) { echo mixedList($v); }
echo "\n";
foreach ([10, 20, 30, 40, 0, -10] as $v) { echo intNeedle($v); }
echo "\n";

$big = [];
for ($i = 0; $i < 100; $i++) { $big[] = $i % 2 === 0 ? 100 + $i : null; }
$hits = 0;
foreach ($big as $v) {
    if (in_array($v, [T_AND_EQUAL, T_BOOLEAN_AND, T_BOOLEAN_OR, T_CONCAT_EQUAL, T_DIV_EQUAL, T_DOUBLE_ARROW], true)) { $hits++; }
}
echo $hits, "\n";
$ids = [T_AND_EQUAL, T_BOOLEAN_AND, null, T_DOUBLE_ARROW, 99999];
foreach ($ids as $id) {
    echo in_array($id, [T_AND_EQUAL, T_BOOLEAN_AND, T_BOOLEAN_OR, T_CONCAT_EQUAL, T_DIV_EQUAL, T_DOUBLE_ARROW], true) ? 'y' : 'n';
}
echo "\n";
