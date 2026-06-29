<?php
$a = 0b1100;
$b = 0b1010;
echo $a & $b; echo "\n";   // 8
echo $a | $b; echo "\n";   // 14
echo $a ^ $b; echo "\n";   // 6
echo ~$a; echo "\n";       // -13
echo 1 << 4; echo "\n";    // 16
echo 256 >> 2; echo "\n";  // 64
echo -8 >> 1; echo "\n";   // -4 (arithmetic)
$c = 3;
$c <<= 2; echo $c; echo "\n";  // 12
$c >>= 1; echo $c; echo "\n";  // 6
$c &= 4; echo $c; echo "\n";   // 4
$c |= 1; echo $c; echo "\n";   // 5
$c ^= 7; echo $c; echo "\n";   // 2
echo PHP_INT_MAX; echo "\n";
