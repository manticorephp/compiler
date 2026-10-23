<?php
// foreach over an IteratorAggregate whose getIterator() is a generator leaks the generator per loop.
final class RG implements IteratorAggregate {
    public function __construct(private \Closure $gen) {}
    public function getIterator(): \Traversable { $g = $this->gen; yield from $g(); }
}
function walk(RG $r): int { $n = 0; foreach ($r as $s) { $n += $s; } return $n; }
$rg = new RG(function () { yield 1; yield 2; });
for ($i = 0; $i < 20000; $i++) { walk($rg); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { walk($rg); }
echo (memory_get_usage() - $b) < 4000000 ? "flat" : "leak", "\n";
