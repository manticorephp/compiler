<?php

use Manticore\Ds\Int32Array;

$a = new Int32Array(3);         // three zeros, 4 bytes each
$a[0] = 10;
$a[1] = '20';                   // a whole numeric string is accepted
$a[] = 30;                      // grows the array

echo count($a), ' ', $a->sum(), ' ', $a->max(), "\n";
echo json_encode($a), "\n";

try {
    $a[0] = 2147483648;         // one past the maximum: nothing wraps
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $a[0] = 1.5;
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo $a[4];
} catch (OutOfBoundsException $e) {
    echo $e->getMessage(), "\n";
}

$a->apply(fn(int $x, int $i) => $x * 2);
echo implode(',', $a->toArray()), "\n";
echo $a->indexOf(40), "\n";
