<?php
$a = "foo";
$b = "bar";
$c = "baz";
$d = "qux";
echo $a . $b . $c . $d . "\n";
$n = 3;
$f = 1.5;
echo "x=" . $n . " y=" . $f . " z=" . ($a . $b) . "\n";
$ns = null;
echo "[" . $ns . $a . "]\n";
$s = "";
for ($i = 0; $i < 3; $i++) {
    $s = $s . "(" . $i . ")";
}
echo $s . "\n";
