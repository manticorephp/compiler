<?php
final class D { public function __construct(public string $n) {} public function __destruct() { echo "d ", $this->n, "\n"; } }
final class Bad { public function __destruct() { echo "bad dtor\n"; } }
function inner(): void { $a = new D('inner'); throw new \LogicException('boom'); }
function mid(): void { $b = new D('mid'); $c = new Bad(); inner(); }
try { mid(); } catch (\LogicException $e) { echo "caught ", $e->getMessage(), "\n"; }
echo "end\n";
