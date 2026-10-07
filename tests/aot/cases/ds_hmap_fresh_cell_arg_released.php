<?php
final class D { public function __construct(public string $n) {} public function __destruct() { echo "~{$this->n}\n"; } }
function mk(string $n): mixed { return new D($n); }
function pass(mixed $v): mixed { return $v; }

$h = __mc_hmap_alloc(0);
__mc_hmap_put($h, 1, mk('v'));
__mc_hmap_del($h, 1);
echo "after v\n";
$o = new D('keep');
__mc_hmap_put($h, 2, pass($o));
__mc_hmap_del($h, 2);
echo "after pass\n";
__mc_hmap_put($h, mk('k'), 1);
echo "put k\n";
__mc_hmap_clear($h);
echo "after k\n";
__mc_hmap_free($h);
unset($o);
echo "end\n";
