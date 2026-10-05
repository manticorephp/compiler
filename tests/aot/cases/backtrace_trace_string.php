<?php
class A {
    public static function s(int $n) { return (new A)->m($n); }
    public function m(int $n) {
        $f = function () use ($n) { throw new RuntimeException("boom $n"); };
        return $f();
    }
}
function chain(int $n) { return A::s($n); }

try {
    chain(1);
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
        chain(2);
    } catch (Exception $e) {
        echo "inner ", count($e->getTrace()), "\n";
        try {
            chain(3);
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
    chain(4);
} catch (Exception $e) {
    echo count($e->getTrace()), "\n";
}
