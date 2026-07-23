<?php
// Fiber round-trip: driver <-> worker exchange values N times (resume carries a
// value in, suspend carries one out). Bidirectional switch + value channel.
$N = 500000;
$worker = new Fiber(function() use ($N) {
    $acc = 0;
    for ($i = 0; $i < $N; $i++) {
        $x = Fiber::suspend($acc);
        $acc = ($acc + $x) % 1000000007;
    }
    return $acc;
});
$worker->start();
$sum = 0;
for ($i = 1; $i <= $N; $i++) {
    $out = $worker->resume($i);
    $sum = ($sum + $out) % 1000000007;
}
echo $sum, "\n";
