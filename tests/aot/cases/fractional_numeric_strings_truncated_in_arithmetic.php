<?php
// "10" + "5.5" yields int(15) instead of float(15.5), and "9" ** 2 yields float(81) instead of int(81)
$a = explode(",", "10,5.5");
var_dump($a[0] + $a[1]);
$n = explode(",", "9");
var_dump($n[0] ** 2);
