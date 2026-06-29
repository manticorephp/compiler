<?php
$a = 100;
$b = 7;
$mix = fn(int $x): int => $a + $b * $x;
echo $mix(2);
