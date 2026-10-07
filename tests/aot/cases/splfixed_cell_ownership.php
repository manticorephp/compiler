<?php
class D { public function __construct(public string $n) {} public function __destruct() { echo "dtor {$this->n}\n"; } }
$a = new SplFixedArray(3);
$a[0] = new D('a'); $a[1] = new D('b'); $a[2] = new D('c');
$a[1] = new D('b2');
echo "overwritten\n";
unset($a[0]);
echo "unset\n";
$a->setSize(1);
echo "shrunk\n";
$b = clone $a;
$a[0] = new D('z');
echo "count ", count($a), " ", count($b), "\n";
var_dump($b[0]);
unset($a);
echo "a gone\n";
$b->setSize(4);
$b[3] = new D('late');
$c = clone $b;
unset($b);
echo "b gone\n";
unset($c);
echo "end\n";
function scope(): void { $t = new SplFixedArray(2); $t[0] = new D('scoped'); $t[1] = [new D('nested')]; }
scope();
echo "after scope\n";
for ($i = 0; $i < 200000; $i++) { $f = new SplFixedArray(4); $f[0] = 'k' . $i; $f[1] = [$i]; $g = clone $f; $g->setSize(2); $x = $f[0]; }
echo memory_get_peak_usage() < 64 * 1024 * 1024 ? "flat\n" : "LEAK\n";
