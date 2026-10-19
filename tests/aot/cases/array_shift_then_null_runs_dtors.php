<?php
class D { public function __construct(public string $n) {} public function __destruct() { echo "~", $this->n, " "; } }
function a(): void { $q = [new D('a'), new D('b')]; $q = null; echo "end\n"; }
function b(): void { $q = [new D('a'), new D('b')]; array_pop($q); $q = null; echo "end\n"; }
function c(): void { $q = [new D('a'), new D('b')]; array_shift($q); echo "mid "; $q = null; echo "end\n"; }
function d(): void { $q = [new D('a'), new D('b'), new D('c')]; array_shift($q); echo "mid "; }
function e(): void { $q = [new D('a'), new D('b'), new D('c')]; $x = array_shift($q); echo "mid "; $x = null; echo "m2 "; $q = null; echo "end\n"; }
a(); b(); c(); d(); echo "\n"; e();
function f(): void { $q = []; for ($i = 0; $i < 6; $i++) { $q[] = new D("o$i"); } array_shift($q); array_shift($q); array_unshift($q, new D('u')); echo "mid "; $q = null; echo "end\n"; }
echo "\n"; f();
