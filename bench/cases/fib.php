<?php
// fib — recursive fibonacci (call overhead + int arithmetic). The depth is a
// data-dependent chain (seeded by $argc, kept in [30,33]) so each fib() call's
// argument depends on the running result — the optimizer can't hoist / fold it.
function fib(int $n): int { return $n < 2 ? $n : fib($n - 1) + fib($n - 2); }
$s = 0;
$d = 30 + ($argc - 1);
for ($i = 0; $i < 40; $i++) {
    $r = fib($d);
    $s += $r;
    $d = 30 + ($r & 3);
}
echo $s, "\n";
