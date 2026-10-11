<?php
// try in a generator restores stale backtrace depth
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
