<?php
// call_user_func_array() with a runtime array into a variadic closure parameter crashes (SIGSEGV).
function g(callable $cb, array $row) { return call_user_func_array($cb, $row); }
echo g(fn(...$x) => array_sum($x), [1, 4, 3]), "\n";
