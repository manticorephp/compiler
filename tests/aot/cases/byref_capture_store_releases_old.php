<?php
class X { function __destruct() { echo "dtor\n"; } }
$e = null;
$f = function () use (&$e) { $e = new X; };
$f();
$f();
echo "mid\n";
$e = null;
echo "end\n";

$s = null;
$g = function (int $i) use (&$s) { $s = str_repeat('x', 100) . $i; };
for ($i = 0; $i < 300000; $i++) { $g($i); }
echo strlen($s), "\n";
$a = null;
$h = function (int $i) use (&$a) { $a = [$i, $i + 1, str_repeat('y', 50)]; };
for ($i = 0; $i < 300000; $i++) { $h($i); }
echo count($a), "\n";
$o = null;
$k = function () use (&$o) { $o = new X; };
$k();
$o = 5;
echo "done\n";
