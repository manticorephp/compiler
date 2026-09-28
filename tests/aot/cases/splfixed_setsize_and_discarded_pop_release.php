<?php
// SplFixedArray::setSize shrank by array_slice (the old elements stayed
// counted), and a discarded array_pop/array_shift never released the element.
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
function a(): void { $s = new SplFixedArray(2); $s[0] = new Tok('a0'); $s->setSize(2); echo "x\n"; }
function b(): void { $s = new SplFixedArray(2); $s[0] = new Tok('b0'); $s->setSize(1); echo "x\n"; }
function c(): void { $s = new SplFixedArray(2); $s[0] = new Tok('c0'); $s->setSize(0); echo "x\n"; }
function d(): void { $s = new SplFixedArray(0); $s->setSize(2); $s[0] = new Tok('d0'); echo "x\n"; }
a(); b(); c(); d(); echo "end\n";
function e(): void { $s = new SplFixedArray(3); $s[0] = 1; $s[1] = 2; $s[2] = 3; $s->setSize(1); $s->setSize(3); $s[2] = 9; var_dump($s->toArray()); }
e();
function pf(): void { $a = [new Tok('p0'), new Tok('p1')]; array_pop($a); echo "x\n"; array_shift($a); echo "y\n"; $s = ['a' . rand(0,0), 'b']; array_pop($s); $n = [1, 2]; array_pop($n); echo count($s) + count($n), "\n"; }
pf(); echo "end2\n";
