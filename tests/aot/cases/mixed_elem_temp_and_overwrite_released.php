<?php
// A fresh object handed to a `mixed` parameter was never released by the
// caller, and an overwritten or unset CELL element was never dropped: every
// SplFixedArray::offsetSet in php-cs-fixer leaked its token.
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
class Fx {
    /** @var array<int, mixed> */
    private array $__data = [null, null];
    public function setP(int $index, mixed $value): void { $this->__data[$index] = $value; }
}
function d(): void { $s = new Fx(); $t = new Tok('d'); $s->setP(0, $t); unset($t); echo "x\n"; unset($s); echo "y\n"; }
function e(): void { $s = new Fx(); $s->setP(0, new Tok('e')); echo "x\n"; unset($s); echo "y\n"; }
function f(): void { $s = new Fx(); $s->setP(0, new Tok('f1')); $s->setP(0, new Tok('f2')); echo "x\n"; }
d(); e(); f(); echo "end\n";
function g(): void { $s = new SplFixedArray(2); $s[0] = new Tok('g1'); $s[0] = new Tok('g2'); unset($s[0]); echo "x\n"; }
g(); echo "end2\n";
