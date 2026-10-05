<?php
class D { public static int $n = 0; public function __destruct() { self::$n++; } }
for ($i = 0; $i < 1000000; $i++) { $d = new D; }
$a = [];
for ($i = 0; $i < 200000; $i++) { $a[] = new D; }
for ($i = 0; $i < 200000; $i++) { $a[$i] = null; }
echo D::$n, "\n";
class K { public static $keep = []; }
K::$keep[] = new class(7) { public function __construct(private int $i) {} public function __destruct() { echo "keep {$this->i}\n"; } };
echo "end\n";
