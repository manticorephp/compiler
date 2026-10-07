<?php
// foreach over an IteratorAggregate (SplFixedArray) never released the iterator
// getIterator() handed it, and so pinned the array and every element.
final class Tok { public function __construct(public string $n) {} public function __destruct() { echo "dtor ", $this->n, "\n"; } }
class Sub extends SplFixedArray {}
function a(): void { $s = new SplFixedArray(2); $s[0] = new Tok('a0'); $s[1] = new Tok('a1'); foreach ($s as $i => $t) { echo $i; } echo "\n"; }
function b(): void { $s = new Sub(2); $s[0] = new Tok('b0'); $s[1] = new Tok('b1'); foreach ($s as $t) { echo $t->n; } echo "\n"; }
function c(SplFixedArray $s): void { foreach ($s as $i => $t) { echo $i; } echo "\n"; }
a(); echo "-\n"; b(); echo "-\n"; $s = new Sub(1); $s[0] = new Tok('c0'); c($s); unset($s); echo "end\n";
