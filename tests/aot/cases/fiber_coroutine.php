<?php
$acc = new Fiber(function() {
    $sum = 0;
    while (true) {
        $add = Fiber::suspend($sum);
        if ($add === null) { break; }
        $sum += $add;
    }
    return "final=$sum";
});
echo "start: ", $acc->start(), "\n";
echo "r1: ", $acc->resume(10), "\n";
echo "r2: ", $acc->resume(20), "\n";
echo "r3: ", $acc->resume(5), "\n";
var_dump($acc->isSuspended());
$acc->resume(null);
var_dump($acc->isTerminated());
echo "return: ", $acc->getReturn(), "\n";
