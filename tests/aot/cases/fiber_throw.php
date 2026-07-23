<?php
$f = new Fiber(function() {
    try {
        echo "before suspend\n";
        Fiber::suspend();
        echo "not reached\n";
    } catch (\Throwable $e) {
        echo "fiber caught: " . $e->getMessage() . "\n";
    }
    echo "fiber done\n";
    return "ret";
});
$f->start();
echo "main between\n";
$r = $f->throw(new \RuntimeException("injected"));
echo "throw returned null: "; var_dump($r === null);
var_dump($f->isTerminated());
echo "getReturn: " . $f->getReturn() . "\n";
