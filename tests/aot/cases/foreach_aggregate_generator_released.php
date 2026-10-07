<?php
// The generator an IteratorAggregate's getIterator() hands a foreach is the
// loop's own: it goes when the loop ends (normally, by break, by return), and
// with it the subject it holds.
final class C implements \IteratorAggregate {
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
    public function getIterator(): \Generator { yield 1; yield 2; yield 3; }
}
final class I implements \IteratorAggregate {
    public function __construct(public string $n) {}
    public function __destruct() { echo "~", $this->n, "\n"; }
    public function getIterator(): \Iterator { yield 1; yield 2; }
}
function all(): void { $c = new C('all'); foreach ($c as $v) { echo $v; } echo "\n"; unset($c); echo "all done\n"; }
function brk(): void { $c = new C('brk'); foreach ($c as $v) { echo $v; break; } echo "\n"; unset($c); echo "brk done\n"; }
function ret(): int { $c = new C('ret'); foreach ($c as $v) { if ($v === 2) { return $v; } } return 0; }
function iface(): void { $c = new I('iface'); foreach ($c as $k => $v) { echo $k, '=', $v, ' '; } echo "\n"; unset($c); echo "iface done\n"; }
all(); brk(); echo ret(), "\n"; echo "ret done\n"; iface();
