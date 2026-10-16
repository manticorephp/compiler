<?php
// foreach over a value typed only `Traversable` must ask the object whether it is an Iterator, an IteratorAggregate or a Generator.
final class RG implements IteratorAggregate, Countable {
    public function __construct(private \Closure $gen, private int $n) {}
    public function getIterator(): \Traversable { $g = $this->gen; yield from $g(); }
    public function count(): int { return $this->n; }
}
final class Lines implements IteratorAggregate {
    public function __construct(private array $a) {}
    public function getIterator(): \Traversable { return new ArrayIterator($this->a); }
}
final class Resetter {
    public function __construct(private \Traversable $items) {}
    public function sum(): int { $n = 0; foreach ($this->items as $id => $s) { $n += $s; } return $n; }
    public function firstKey(): string|int|null { foreach ($this->items as $k => $_) { return $k; } return null; }
    public function upTo(int $max): int { $n = 0; foreach ($this->items as $s) { if ($s > $max) { break; } $n += $s; } return $n; }
}
function gen() { yield 7; yield 8; yield 9; }
$use = new ArrayIterator([1]); foreach ($use as $x) {}
$rg = new RG(function () { yield 'a' => 1; yield 'b' => 2; }, 2);
echo (new Resetter($rg))->sum(), "\n";
echo (new Resetter(new ArrayIterator([4, 5])))->sum(), "\n";
echo (new Resetter(gen()))->sum(), "\n";
echo (new Resetter(new Lines([10, 20, 30])))->sum(), "\n";
echo (new Resetter($rg))->firstKey(), "|", (new Resetter(new Lines([3, 4])))->firstKey(), "\n";
echo (new Resetter(new Lines([1, 2, 30, 4])))->upTo(5), "\n";
$b = memory_get_usage();
for ($i = 0; $i < 100000; $i++) { (new Resetter(new Lines([1, 2, 3])))->sum(); (new Resetter(new Lines([1, 9, 3])))->upTo(5); (new Resetter(new ArrayIterator([1, 2])))->sum(); }
echo (memory_get_usage() - $b) < 4000000 ? "flat" : "leak", "\n";
