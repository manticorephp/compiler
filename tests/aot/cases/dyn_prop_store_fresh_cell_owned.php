<?php
class H { public function __construct(public string $n) {} public function __destruct() { echo "drop {$this->n}\n"; } }
function mk($n): mixed { return new H($n); }
function t2() { $o = new stdClass(); $k = 'a'; $h = mk("borrow"); $o->$k = $h; unset($h); echo "kept {$o->$k->n}\n"; }
function t4() { $o = new stdClass(); $k = 'a'; $x = 1; $o->$k = &$x; $o->$k = 2; var_dump($x); $o->$k = new H("thru"); echo "thru\n"; $o->$k = 3; var_dump($x); }
function t6() { $o = new stdClass(); $k = 'a'; $o->$k = "x" . str_repeat("y", 5); $s = $o->$k; $o->$k = "z"; echo "$s\n"; }
function t8() { $o = new stdClass(); foreach (['a', 'b'] as $k) { $o->$k = mk($k); } $p = clone $o; unset($o); echo "cloned {$p->a->n}\n"; }
foreach (['t2','t4','t6','t8'] as $t) { $t(); echo "== $t\n"; }
class V { public function value(int $i): mixed { return str_repeat("x", 40); } }
function grow() { $v = new V(); $m0 = 0; for ($i = 0; $i < 30000; $i++) { $o = new stdClass(); for ($j = 0; $j < 4; $j++) { $k = "k$j"; $o->$k = $v->value($j); } if ($i === 300) { $m0 = memory_get_peak_usage(); } } return memory_get_peak_usage() - $m0 < 3000000 ? "flat" : "grew"; }
echo grow(), "\n";
