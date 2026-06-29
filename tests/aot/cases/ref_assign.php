<?php
$x = 5;
$y = &$x;
$y = $y + 10;
echo $x, ",", $y;
$x = 100;
echo ",", $y;
