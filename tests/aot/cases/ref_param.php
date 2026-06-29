<?php
function bump(int &$x): void { $x = $x + 1; }
$n = 10;
bump($n); bump($n); bump($n);
echo $n;
