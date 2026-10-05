<?php
function down(int $n) {
    if ($n === 0) { throw new Exception("bottom"); }
    return down($n - 1) + 1;
}
function sum(int $n) {
    if ($n === 0) { return 0; }
    return $n + sum($n - 1);
}
try {
    down(5000);
} catch (Exception $e) {
    $t = $e->getTrace();
    echo count($t), "\n";
    echo $t[0]['function'], ' ', $t[1]['function'], "\n";
    $c = count($t);
    echo $t[$c - 2]['function'], ' ', $t[$c - 1]['function'], "\n";
}
echo sum(5000), "\n";
