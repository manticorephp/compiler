<?php
list($a, $b) = [1, 2];
echo $a, $b, "\n";
list($p, , $r) = [10, 20, 30];
echo $p, $r, "\n";
list('x' => $x, 'y' => $y) = ['x' => 7, 'y' => 8];
echo $x, $y, "\n";
list(list($m, $n), $o) = [[4, 5], 6];
echo $m, $n, $o, "\n";
