<?php
function one(Closure $f, array $a) { return $f(...$a); }
function two(Closure $f, $x, array $a) { return $f($x, ...$a); }
echo one(fn(...$p) => implode(",", $p), [2, 3]), "\n";
echo one(fn(...$p) => count($p), []), "\n";
echo two(fn($h, ...$p) => $h . ':' . implode(",", $p), 'h', [4, 5, 6]), "\n";
echo two(fn(int $h, int ...$p) => $h + array_sum($p), 1, [4, 5, 6]), "\n";
class C {
    public $cb;
    function __construct() { $this->cb = fn(...$a) => count($a); }
    function run(array $a, $x) { echo ($this->cb)($x, ...$a), ($this->cb)(...$a, ...$a), "\n"; }
}
(new C)->run([1, 2], 0);
