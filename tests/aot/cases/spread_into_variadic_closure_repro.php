<?php
// Spreading an array into a variadic Closure parameter crashes (SIGSEGV) instead of passing the elements.
function one(Closure $f, array $a) { return $f(...$a); }
echo one(fn(...$p) => implode(",", $p), [2, 3]), "\n";
