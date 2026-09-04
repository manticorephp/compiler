<?php
// The bucket index for an INT key is hashed, not used raw (an identity hash puts
// sequential keys in one contiguous run and makes backshift deletion O(n)). Every
// site that computes an int home has to agree, so churn the index hard and check
// the array still answers exactly what php answers.

$v = [];
for ($i = 0; $i < 400; $i++) {
    $v[$i] = $i * 3;
}
// Evict and re-insert every key several times over, out of order.
for ($round = 0; $round < 6; $round++) {
    for ($i = 0; $i < 400; $i++) {
        $k = ($i * 137 + $round * 31) % 400;
        unset($v[$k]);
        $v[$k] = $k * 3 + $round;
    }
}
echo count($v), "\n";
$sum = 0;
$missing = 0;
for ($i = 0; $i < 400; $i++) {
    if (!isset($v[$i])) {
        $missing++;
        continue;
    }
    $sum += $v[$i];
}
echo 'missing=', $missing, ' sum=', $sum, "\n";
echo 'spot=', $v[0], ',', $v[199], ',', $v[399], "\n";

// Holes that stay holes, and keys above the range.
for ($i = 0; $i < 400; $i += 3) {
    unset($v[$i]);
}
$v[1000] = 'high';
$v[7] = 'seven';
echo count($v), ' ', var_export(isset($v[0]), true), ' ', var_export(isset($v[1]), true), "\n";
echo $v[1000], ' ', $v[7], "\n";

// Order is entry order, not bucket order.
$k5 = [];
$n = 0;
foreach ($v as $k => $_) {
    $k5[] = $k;
    if (++$n === 5) {
        break;
    }
}
echo implode(',', $k5), "\n";
echo json_encode(array_slice($v, 0, 4, true)), "\n";

// Negative and large keys go through the same mixer.
$w = [];
$w[-5] = 'neg';
$w[PHP_INT_MAX] = 'max';
$w[0] = 'zero';
unset($w[0]);
$w[0] = 'zero2';
echo json_encode($w), ' ', count($w), "\n";
