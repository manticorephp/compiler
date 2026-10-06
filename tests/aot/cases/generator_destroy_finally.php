<?php
final class D { public function __destruct() { echo "d\n"; } }
function g(): \Generator { $o = new D(); try { yield 1; yield 2; } finally { echo "finally\n"; } }
function run(): void { $x = g(); $x->current(); unset($x); echo "unset done\n"; }
run();
