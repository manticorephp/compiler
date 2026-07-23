<?php
$f = new Fiber(function() {
    try {
        echo "f: in try\n";
        Fiber::suspend();
        echo "f: after suspend (still in try)\n";
    } catch (\Throwable $e) {
        echo "f: caught " . $e->getMessage() . "\n";
    }
    echo "f: done\n";
});
$f->start();
try {
    echo "main: in try\n";
    throw new \RuntimeException("main-ex");
} catch (\Throwable $e) {
    echo "main: caught " . $e->getMessage() . "\n";
}
$f->resume();
echo "main: end\n";
