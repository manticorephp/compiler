<?php
// array_map with several arrays: N-ary callback, null padding, list result, null callback zips.
print_r(array_map(fn($a, $b) => $a . $b, ['a', 'b'], ['x', 'y']));
print_r(array_map(null, [1, 2], [3, 4]));
print_r(array_map(null, [1, 2, 3], ['a']));
print_r(array_map(fn($a, $b, $c) => ($a ?? 'n') . ($b ?? 'n') . ($c ?? 'n'), [1, 2, 3], [4], [5, 6]));
print_r(array_map(fn($a, $b) => $a + $b, ['x' => 1, 'y' => 2], [10 => 10, 20 => 20]));
print_r(array_map(null, ['k' => 1]));
print_r(array_map(fn($v) => $v * 2, ['k' => 1, 'j' => 2]));
function zjoin($a, $b) { return $a . '-' . $b; }
print_r(array_map('zjoin', ['a', 'b'], [2, 3]));
print_r(array_map(null, [], []));
