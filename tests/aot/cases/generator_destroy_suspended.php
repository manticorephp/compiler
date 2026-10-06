<?php
final class D { public function __construct(public string $n) {} public function __destruct() { echo "d ", $this->n, "\n"; } }
function gen(): \Generator { $a = new D('a'); yield 1; $b = new D('b'); yield 2; echo "never\n"; }
function run(): void { $g = gen(); echo $g->current(), "\n"; $g->next(); echo $g->current(), "\n"; unset($g); echo "after unset\n"; }
run();
echo "end\n";
