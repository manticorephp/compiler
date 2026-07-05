<?php
// Exception stack traces + debug_backtrace. getLine / getTrace and the call
// chain (function + method + static frames). getTrace() and debug_backtrace()
// return PHP-shaped frames {file, function[, class, type]} (innermost first);
// this checks function/class/type + getLine, which match PHP exactly.
function inner() { throw new RuntimeException("fail", 7); }
function middle() { inner(); }
class Svc {
    function run() { middle(); }
    static function boot() { (new Svc)->run(); }
}
try {
    Svc::boot();
} catch (RuntimeException $e) {
    echo $e->getMessage(), " / ", $e->getCode(), " / line ", $e->getLine(), "\n";
    $t = $e->getTrace();
    echo "frames=", count($t), "\n";
    foreach ($t as $f) {
        $cls = isset($f['class']) ? $f['class'] . $f['type'] : "";
        echo "  ", $f['line'], " ", $cls, $f['function'], "\n";
    }
}
// depth is restored after a catch — a second trace is not inflated.
try { inner(); } catch (RuntimeException $e) {
    echo "second=", count($e->getTrace()), "\n";
}
// debug_backtrace inside a call chain — same frame shape.
function d0() { return debug_backtrace(); }
function d1() { return d0(); }
$bt = d1();
echo "bt=", count($bt), ": ", $bt[0]['function'], ",", $bt[1]['function'], "\n";
