<?php
// Spreading an array across a fixed parameter and the variadic parameter of a Closure crashes (SIGSEGV).
// issue: #385
$g = fn($a, ...$r) => $a . ":" . implode(",", $r);
$arr = [1, 2, 3];
echo $g(...$arr), "\n";
