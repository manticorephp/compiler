<?php
$a = [1, 2, 3];
$b = $a;
$b[] = 4;
$a[] = 99;
echo $a[0]; echo $a[1]; echo $a[2]; echo "\n";
echo count($a); echo "/"; echo count($b); echo "\n";
echo $b[3]; echo "\n";
echo $a[3]; echo "\n";
