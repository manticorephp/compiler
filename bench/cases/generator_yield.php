<?php
// Generator throughput: N yields drained by a foreach, summing the values.
function gen(int $n) {
    for ($i = 0; $i < $n; $i++) {
        yield $i;
    }
}
$N = 5000000;
$sum = 0;
foreach (gen($N) as $v) {
    $sum = ($sum + $v) % 1000000007;
}
echo $sum, "\n";
