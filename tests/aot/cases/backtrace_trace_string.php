<?php
class A {
    public static function s() { return (new A)->m(); }
    public function m() { return B::t(); }
}
class B {
    public static function t() { throw new RuntimeException("boom"); }
}
function chain() { return A::s(); }

try {
    chain();
} catch (Exception $e) {
    echo $e->getTraceAsString(), "\n";
    echo preg_replace('#\S*/backtrace_trace_string\.php#', 'FILE', (string)$e), "\n";
    echo count($e->getTrace()), "\n";
    foreach ($e->getTrace() as $i => $fr) {
        echo $i, ' ', $fr['function'], ' ', $fr['class'] ?? '-', ' ', $fr['type'] ?? '-', ' ', $fr['line'] ?? '-', "\n";
    }
}

function outer() {
    try {
        chain();
    } catch (Exception $e) {
        echo "inner ", count($e->getTrace()), "\n";
        try {
            chain();
        } catch (Exception $e2) {
            echo "nested ", count($e2->getTrace()), "\n";
        }
        throw new LogicException("second");
    }
}
try {
    outer();
} catch (Exception $e) {
    echo $e->getTraceAsString(), "\n";
    echo count($e->getTrace()), "\n";
}
try {
    chain();
} catch (Exception $e) {
    echo count($e->getTrace()), "\n";
}
