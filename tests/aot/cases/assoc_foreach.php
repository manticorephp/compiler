<?php
$totals = ["a" => 10, "b" => 20, "c" => 30];
$sum = 0;
foreach ($totals as $k => $v) {
    $sum += $v;
}
echo $sum;
