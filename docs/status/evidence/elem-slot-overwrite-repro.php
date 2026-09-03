<?php
class C { public static int $f = 0; }
class T { public string $n = ''; public function __destruct() { C::$f = C::$f + 1; } }

function build(int $n): array {
    $m = [];
    for ($i = 0; $i < $n; $i++) { $t = new T(); $t->n = "k" . $i; $m["k" . $i] = $t; }
    return $m;
}
// 1. plain unset
$m = build(100);
foreach (array_keys($m) as $k) { unset($m[$k]); }
echo "unset only      freed=", C::$f, " of 100\n";
// 2. overwrite with null, then unset
C::$f = 0;
$m2 = build(100);
foreach (array_keys($m2) as $k) { $m2[$k] = null; unset($m2[$k]); }
echo "null then unset freed=", C::$f, " of 100\n";
// 3. overwrite with another object
C::$f = 0;
$m3 = build(100);
foreach (array_keys($m3) as $k) { $m3[$k] = new T(); }
echo "overwrite       freed=", C::$f, " of 100 (old ones)\n";
