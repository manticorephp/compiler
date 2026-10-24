<?php
// array_map with several arrays (and array_map(null, ...)) only walks the first array
print_r(array_map(fn($a, $b) => $a . $b, ['a', 'b'], ['x', 'y']));
print_r(array_map(null, [1, 2], [3, 4]));
