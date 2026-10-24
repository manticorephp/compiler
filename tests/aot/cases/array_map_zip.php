<?php
print_r(array_map(fn($a, $b) => $a . $b, ['a', 'b'], ['x', 'y']));
print_r(array_map(null, [1, 2], [3, 4]));
print_r(array_map(fn($a, $b) => ($a ?? 'n') . ($b ?? 'n'), ['p' => 'a', 'q' => 'b', 'c'], ['x']));
print_r(array_map(fn($a, $b, $c) => $a + $b + $c, [1, 5], [4, 2], [3, 3]));
