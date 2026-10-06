<?php
// A destructor that throws while an exception unwinds the frame: the new one
// takes the one in flight as its previous, and the frame's other locals are
// still destroyed.
final class Bad { public function __construct(public string $n) {} public function __destruct() { echo "bad ", $this->n, "\n"; throw new LogicException('dtor ' . $this->n); } }
final class D { public function __construct(public string $n) {} public function __destruct() { echo "d ", $this->n, "\n"; } }
function leaf(int $i): int { if ($i >= 0) { throw new RuntimeException('first'); } return $i; }
function g(int $i): int { $a = new D('a'); $b = new Bad('b'); $c = new D('c'); return leaf($i) + strlen($a->n . $b->n . $c->n); }
function h(int $i): int { $a = new Bad('x'); $b = new Bad('y'); return leaf($i) + strlen($a->n . $b->n); }
function dump(Throwable $e): void { for ($x = $e; $x !== null; $x = $x->getPrevious()) { echo get_class($x), ':', $x->getMessage(), "\n"; } }
try { g(1); } catch (Exception $e) { dump($e); }
try { h(1); } catch (Exception $e) { dump($e); }
echo "end\n";
