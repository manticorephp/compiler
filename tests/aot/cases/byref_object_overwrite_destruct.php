<?php
final class D { public function __construct(public string $n) {} public function __destruct() { echo "gone ", $this->n, "\n"; } }
function repl(D &$d, string $n): void { $d = new D($n); echo "replaced\n"; }
function run(): void { $x = new D('a'); repl($x, 'b'); echo "back ", $x->n, "\n"; $arr = [new D('c')]; repl($arr[0], 'd'); echo "arr ", $arr[0]->n, "\n"; }
run();
echo "end\n";
