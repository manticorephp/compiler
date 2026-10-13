<?php
// A try inside a generator restores the backtrace depth saved at try entry. The generator is
// started at the top level and its catch runs when resumed from a deep call: the depth drops to the
// stale value, the callers' pops then take it negative and count(getTrace()) is garbage
// (a large negative on main); a later push writes before the ring. php: 0.
function g() {
    try {
        yield 1;
        throw new Exception('x');
    } catch (Exception $e) {
        yield 2;
    }
}
function deep(int $n, Generator $gen) {
    if ($n > 0) { deep($n - 1, $gen); return; }
    $gen->next();
}
$gen = g();
$gen->current();
deep(50, $gen);
echo count((new Exception('t'))->getTrace()), "\n";
