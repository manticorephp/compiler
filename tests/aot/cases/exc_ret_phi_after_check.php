<?php
// Values merged from calls that may throw (ternary, ??, &&) stay right when the call's block is split by the exception check.
function f(int $x): ?int { if ($x < 0) { throw new InvalidArgumentException("neg $x"); } return $x === 0 ? null : $x * 2; }
function g(int $x): int { if ($x > 100) { throw new RangeException("big $x"); } return $x + 1; }
foreach ([3, 0, -1, 200, 7] as $v) {
    try {
        $a = $v > 50 ? g($v) : f($v);
        $b = f($v) ?? g($v);
        $c = f($v) && g($v) > 2;
        echo $v, ": ", var_export($a, true), " ", $b, " ", var_export($c, true), "\n";
    } catch (Exception $e) {
        echo $v, ": ", get_class($e), " ", $e->getMessage(), "\n";
    }
}
