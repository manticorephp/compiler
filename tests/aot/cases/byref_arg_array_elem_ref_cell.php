<?php
function inc(int &$x): void { $x++; }
$a = [1, 2, 3];
$r = &$a[1];
inc($a[1]);
inc($a[1]);
$r += 10;
echo $a[1], " ", $r, "\n";
inc($a[0]);
echo implode(",", $a), "\n";
