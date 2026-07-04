<?php
// Exception stack traces + debug_backtrace. getLine / getFile / getTrace /
// getTraceAsString and the call chain (function + method + static frames).
// getTrace() is a V1 list of frame function-name strings (innermost first).
function inner() { throw new RuntimeException("fail", 7); }   // line 4
function middle() { inner(); }                                // line 5
class Svc {
    function run() { middle(); }                              // line 8
    static function boot() { (new Svc)->run(); }              // line 9
}
try {
    Svc::boot();                                              // line 12
} catch (RuntimeException $e) {
    echo $e->getMessage(), " / ", $e->getCode(), " / line ", $e->getLine(), "\n";
    $t = $e->getTrace();
    echo "frames=", count($t), "\n";
    foreach ($t as $f) { echo "  ", $f, "\n"; }
}
// depth is restored after a catch — a second trace is not inflated.
try { inner(); } catch (RuntimeException $e) {
    echo "second=", count($e->getTrace()), "\n";
}
// debug_backtrace inside a call chain.
function d0() { return debug_backtrace(); }
function d1() { return d0(); }
$bt = d1();
echo "bt=", count($bt), ": ", $bt[0], ",", $bt[1], "\n";
