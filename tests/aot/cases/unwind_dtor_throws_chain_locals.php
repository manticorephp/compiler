<?php
class D { public function __construct(public string $n, public bool $t) {} public function __destruct() { echo "dtor ", $this->n, "\n"; if ($this->t) { throw new RuntimeException("from " . $this->n); } } }
function boom(): void { throw new LogicException("boom"); }
function f(int $k): string { $a = new D("a", $k > 0); $b = new D("b", false); $c = new D("c", $k > 1); $s = str_repeat("x", $k); boom(); return $s . $a->n . $b->n . $c->n; }
foreach ([0, 1, 2] as $k) { try { f($k); } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(); for ($p = $e->getPrevious(); $p; $p = $p->getPrevious()) { echo " <- ", get_class($p), ": ", $p->getMessage(); } echo "\n"; } }
