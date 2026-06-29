<?php
function double(int $x): int { return $x * 2; }
$f = double(...);
echo $f(5), ",", $f(10);
