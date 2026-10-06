<?php
class Sortable implements \IteratorAggregate {
    public function __construct(private \Traversable $it) {}
    public function getIterator(): \Generator { foreach ($this->it as $k => $v) { yield $k => $v * 10; } }
}
class F {
    /** @var list<string> */
    private array $dirs = [];
    public function __construct(private bool $sort) { $this->dirs = ['x']; }
    private function search(string $d): \Iterator { return new \ArrayIterator([$d => 1, 'y' => 2]); }
    public function getIterator(): \Iterator {
        if (1 === \count($this->dirs)) {
            $iterator = $this->search($this->dirs[0]);
        } else {
            $iterator = new \AppendIterator();
        }
        if ($this->sort) {
            $iterator = (new Sortable($iterator))->getIterator();
        }
        return $iterator;
    }
}
foreach ([true, false] as $s) {
    foreach ((new F($s))->getIterator() as $k => $v) { echo "$k=$v\n"; }
}
