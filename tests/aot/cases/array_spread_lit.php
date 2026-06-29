<?php
$a = [1, 2, 3];
$b = [0, ...$a, 4, 5];
echo count($b), ":";
foreach ($b as $v) { echo $v, ","; }
