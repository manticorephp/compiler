<?php
$ao = new ArrayObject([1, 2, 3]);
foreach ($ao as $k => $v) { if ($k < 5) { $ao[] = 0; } }
echo count($ao), "\n";
$n = 0;
foreach ($ao as $v) { $n++; }
echo $n, "\n";
$ao = new ArrayObject(['a'=>1,'b'=>2,'c'=>3,'d'=>4]);
foreach ($ao as $k => $v) { echo "$k=$v,"; if ($k==='a') { unset($ao['b']); } if ($k==='c') { $ao['e']=5; } }
echo "\n";
$it = $ao->getIterator(); $it['z']=9; echo count($ao), "\n";
$ai = new ArrayIterator([1,2]); foreach ($ai as $v) { if ($v==1) $ai[]=7; echo $v; } echo "\n";
