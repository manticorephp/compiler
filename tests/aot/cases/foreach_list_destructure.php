<?php
$rows = [[1, 2], [3, 4]];
foreach ($rows as [$a, $b]) { echo $a + $b, "\n"; }
$recs = [['x' => 1, 'y' => 2], ['x' => 5, 'y' => 7]];
foreach ($recs as ['x' => $x, 'y' => $y]) { echo $x * $y, "\n"; }
foreach ($rows as $i => [$a, $b]) { echo "$i: $a,$b\n"; }
