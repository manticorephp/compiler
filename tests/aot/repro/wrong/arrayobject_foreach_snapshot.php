<?php
// foreach over an ArrayObject iterates a snapshot: elements appended inside the loop are never visited.
// issue: #300
$ao = new ArrayObject([1, 2, 3]);
foreach ($ao as $k => $v) { if ($k < 5) { $ao[] = 0; } }
echo count($ao), "\n";
$n = 0;
foreach ($ao as $v) { $n++; }
echo $n, "\n";
