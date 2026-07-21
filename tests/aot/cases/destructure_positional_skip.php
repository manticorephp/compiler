<?php
[$a, , $c] = [10, 20, 30];
echo $a, " ", $c, "\n";
[, $y, , $w] = ['p', 'q', 'r', 's'];
echo $y, " ", $w, "\n";
$pairs = [[1, 2, 3], [4, 5, 6]];
foreach ($pairs as [$first, , $third]) {
    echo $first, ":", $third, " ";
}
echo "\n";
