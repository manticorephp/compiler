<?php
function &slot(int &$n): int { return $n; }
$x = 1;
$r = &slot($x);
$r = $r + 5;
echo $x, ",", $r;
$v = slot($x);
echo ",", $v;
