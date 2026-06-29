<?php
$base = 10;
$add = fn(int $x): int => $x + $base;
echo $add(5), ",", $add(7);
