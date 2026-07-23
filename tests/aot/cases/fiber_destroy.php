<?php
// A suspended fiber, dropped at program exit, runs its finally via FiberExit.
$f = new Fiber(function() {
    try {
        echo "before suspend\n";
        Fiber::suspend();
        echo "not reached\n";
    } finally {
        echo "finally ran\n";
    }
});
$f->start();
echo "main end\n";
