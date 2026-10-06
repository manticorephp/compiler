<?php
// An exception out of a loop's own iterator step (a generator's resume, an
// Iterator's current()/next()) leaves the frame through the loop: the frame's
// owned locals and the iterator the loop holds go with it.
final class D { public function __construct(public string $n) {} public function __destruct() { echo "~", $this->n, "\n"; } }
function gen(string $tag): \Generator { $in = new D($tag . '-inner'); yield 1; throw new \RuntimeException($tag); }
final class Agg implements \IteratorAggregate {
    public function __construct(public D $d) {}
    public function getIterator(): \Generator { yield 1; throw new \RuntimeException('agg'); }
}
final class It implements \Iterator {
    private int $i = 0;
    public function __construct(public D $d) {}
    public function current(): mixed { return $this->i; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; if ($this->i === 2) { throw new \RuntimeException('it'); } }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 5; }
}
function overGen(): void { $o = new D('gen-local'); foreach (gen('gen') as $v) { echo "v", $v, "\n"; } echo "never\n"; }
function overAgg(): void { $o = new D('agg-local'); $a = new Agg(new D('agg-subject')); foreach ($a as $v) { echo "v", $v, "\n"; } echo "never\n"; }
function overIt(): void { $o = new D('it-local'); $a = new It(new D('it-subject')); foreach ($a as $v) { echo "v", $v, "\n"; } echo "never\n"; }
function again(): void
{
    for ($r = 0; $r < 2; $r++) {
        $a = new Agg(new D('again' . $r)); try { foreach ($a as $v) { echo "r", $r, "\n"; } } catch (\RuntimeException $e) { echo "caught again ", $r, "\n"; }
    }
    echo "again end\n";
}
foreach (['overGen', 'overAgg', 'overIt'] as $f) {
    try { $f(); } catch (\RuntimeException $e) { echo "caught ", $e->getMessage(), "\n"; }
    echo "-- after ", $f, "\n";
}
again();
echo "end\n";
