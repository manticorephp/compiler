<?php
// Fiber context-switch throughput: a producer suspends N times, the driver
// resumes it N times, summing the yielded values. Pure switch cost.
$N = 1000000;
$f = new Fiber(function() use ($N) {
    for ($i = 0; $i < $N; $i++) {
        Fiber::suspend($i);
    }
});
$sum = 0;
$v = $f->start();
$sum = ($sum + $v) % 1000000007;
for ($i = 1; $i < $N; $i++) {
    $v = $f->resume();
    $sum = ($sum + $v) % 1000000007;
}
echo $sum, "\n";
