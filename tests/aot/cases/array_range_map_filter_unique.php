<?php

// range() int/float/CHARACTER forms, array_filter mode, array_unique flags
// (the default is SORT_STRING, NOT a loose ==), and array_map ZIP form.

// range: int, float, char, descending, stepped.
print_r(range(1, 5));
print_r(range(5, 1));
print_r(range(1, 10, 3));
print_r(range(0, 1, 0.25));
print_r(range(1.5, 3.5));
print_r(range('a', 'e'));
print_r(range('e', 'a'));
print_r(range(5, 1, 2));
print_r(range(1.0, 3.0));
print_r(range(3, 3));

// array_filter modes.
print_r(array_filter(['a' => 1, 'b' => 2, 'c' => 3], fn($k) => $k !== 'b', ARRAY_FILTER_USE_KEY));
print_r(array_filter(['a' => 1, 'b' => 2], fn($v, $k) => $v > 1 && $k === 'b', ARRAY_FILTER_USE_BOTH));
print_r(array_filter([0, 1, 2, '', 'x']));
print_r(array_filter([1, 2, 3, 4], fn($v) => $v % 2 === 0));

// array_unique flags.
print_r(array_unique([1, '1', 2, '2', true]));
print_r(array_unique([1, '1', 2], SORT_REGULAR));
print_r(array_unique(['1', '01', 1], SORT_NUMERIC));
print_r(array_unique(['a', 'b', 'a']));

// array_map: single-array form only (the ZIP form is not implemented — see
// array_fns.php: a variadic on array_map destabilises the self-host).
print_r(array_map(fn($x) => $x * 2, [1, 2, 3]));
print_r(array_map(null, [1, 2]));
print_r(array_map(fn($v) => $v, ['k' => 'v', 'j' => 'w']));
