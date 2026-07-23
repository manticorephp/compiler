<?php
$inner = new Fiber(function() {
    echo "inner A\n";
    $x = Fiber::suspend("iA");
    echo "inner B got $x\n";
    return "inner-ret";
});
$outer = new Fiber(function() use ($inner) {
    echo "outer 1\n";
    $v = $inner->start();
    echo "outer 2 (inner yielded $v)\n";
    Fiber::suspend("oA");
    echo "outer 3\n";
    $inner->resume("resumed-inner");
    echo "outer 4, inner done=" . var_export($inner->isTerminated(), true) . " ret=" . $inner->getReturn() . "\n";
});
$y = $outer->start();
echo "main mid (outer yielded $y)\n";
$outer->resume();
echo "main end\n";
