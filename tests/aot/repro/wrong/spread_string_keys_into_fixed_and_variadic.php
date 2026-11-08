<?php
// Spreading a string-keyed array into a callee with fixed parameters and a variadic drops the keys of the variadic part.
// issue: #574
function h(int $a = 0, mixed ...$rest) { return $a . json_encode($rest); }
echo h(...['a' => 7, 'z' => 1]), "\n";
