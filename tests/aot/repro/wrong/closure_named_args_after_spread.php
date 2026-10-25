<?php
// A named argument after a spread into a closure value is bound by position instead of by parameter name.
// issue: #463
$c = fn(int $a, int $b = 2, $z = 'zz') => "$a $b $z";
echo $c(...[1], z: 'Q'), "\n";
