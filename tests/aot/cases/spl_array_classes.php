<?php
$it = new ArrayIterator(["a" => 1, "b" => 2, "c" => 3]);
echo "count=", count($it), "\n";
foreach ($it as $k => $v) { echo $k, "=", $v, "\n"; }
$it["d"] = 4;
$it[] = 99;
echo "d=", $it["d"], " has_b=", isset($it["b"]) ? "Y":"N", "\n";
unset($it["a"]);
echo "after unset: ";
foreach ($it as $k => $v) { echo $k, ":", $v, " "; }
echo "\n";
$it->append("zz");
echo "appended count=", count($it), "\n";

$ao = new ArrayObject(["x" => 10, "y" => 20]);
echo "ao count=", count($ao), "\n";
foreach ($ao as $k => $v) { echo "ao ", $k, "=", $v, "\n"; }
$ao["z"] = 30;
echo "ao z=", $ao["z"], "\n";
foreach ($ao->getIterator() as $k => $v) { echo "gi ", $k, "=", $v, "\n"; }
