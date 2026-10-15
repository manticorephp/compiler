<?php
// A generator that walks a value typed only Traversable treats an IteratorAggregate as an Iterator.
final class Lines implements IteratorAggregate {
    public function __construct(private array $a) {}
    public function getIterator(): \Traversable { return new ArrayIterator($this->a); }
}
function scaled(\Traversable $it) { foreach ($it as $k => $v) { yield $k => $v * 10; } }
$use = new ArrayIterator([1]); foreach ($use as $x) {}
foreach (scaled(new Lines([1, 2, 3])) as $k => $v) { echo "$k=$v "; }
echo "\n";
