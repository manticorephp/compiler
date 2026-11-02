<?php
// unset() of a name that is later rebound by reference (`$r = &$x[0]`) does not release the value it held, so a reference inside it outlives the unset.
// issue: #551
$a = [1, 2];
$r = [&$a[0]];
unset($r);
$b = $a;
$b[0] = 9;
echo $a[0], "\n";
$h = [1];
$r = &$h[0];
