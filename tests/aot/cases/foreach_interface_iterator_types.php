<?php
// foreach over an INTERFACE-typed iterator (IteratorAggregate::getIterator(): Iterator, a
// function returning Iterator) is classified at run time and its current/key steps answer
// tagged cells; the loop variables were typed from an implementer's narrowed return (int)
// and printed the tag bits.
final class It implements Iterator {
    private int $i = 0;
    public function current(): mixed { return $this->i * 10; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 3; }
}
class Agg implements IteratorAggregate { public function getIterator(): Iterator { return new It(); } }
foreach (new Agg() as $k => $v) { echo "$k:$v "; }
echo "\n";
function g(): Generator { yield "a" => 1; yield 2; }
function it(bool $gen): Iterator { return $gen ? g() : new It(); }
foreach ([true, false] as $b) { foreach (it($b) as $k => $v) { echo var_export($k, true), "=", $v + 1, " "; } echo "\n"; }
