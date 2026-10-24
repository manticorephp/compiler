<?php
$a = explode(",", "10,5.5,3,x");
var_dump($a[0] + $a[1], $a[0] - $a[1], $a[0] * $a[1], $a[0] + $a[2], $a[0] + 1, 2 + $a[1], $a[0] * $a[2]);
$i = 4; $m = $a[0] + $i; var_dump($m); $arr=[1,2,3]; $q = explode(",", "1,2"); var_dump($arr[$q[0] + $q[1] - 1]);
echo $a[0] + $a[2], "\n";
$s = "20"; $t = "22"; var_dump($s + $t, "7" + "3");
$pn = explode(",", "9,2.5,-1,3");
var_dump($pn[0] ** 2, $pn[0] ** $pn[3], $pn[0] ** $pn[1], 2 ** $pn[3], $pn[0] ** $pn[2], 2 ** 3, 2 ** -1, pow($pn[3], 2));
function gpow(int|float $x) { return $x ** 2; } var_dump(gpow(3), gpow(1.5));
