<?php
class C { public static int $f = 0; }
class T {
    public string $n = '';
    public function __destruct() { C::$f = C::$f + 1; }
}
/** @var array<string,T> */
$m = [];
for ($i = 0; $i < 200; $i++) { $t = new T(); $t->n = "k" . $i; $m["k" . $i] = $t; }
echo "built=", count($m), "\n";
foreach (array_keys($m) as $k) {
    $fn = $m[$k];
    unset($m[$k], $fn);
}
echo "after unset freed=", C::$f, " of 200\n";
