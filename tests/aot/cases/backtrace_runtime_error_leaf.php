<?php
declare(strict_types=1);
function f(int $x) { return $x; }
function viaDiv() { return intdiv(1, 0); }
function viaType() { $a = json_decode('["x"]'); return f($a[0]); }
function viaIter() { return new ArrayIterator(1); }
function viaRepeat() { return str_repeat('x', -1); }
foreach (['viaDiv', 'viaType', 'viaIter', 'viaRepeat'] as $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        echo get_class($e), ':';
        foreach ($e->getTrace() as $fr) { echo ' ', $fr['function']; }
        echo "\n";
    }
}
