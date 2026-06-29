<?php
$xs = [1, 2, 3, 4, 5];
$total = 0;
foreach ($xs as $x) {
    if ($x > 3) { break; }
    $total += $x;
}
echo $total;
