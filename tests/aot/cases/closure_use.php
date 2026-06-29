<?php
$count = 0;
$step = function(int $n) use ($count): int { return $count + $n; };
echo $step(3), ",", $step(5);
