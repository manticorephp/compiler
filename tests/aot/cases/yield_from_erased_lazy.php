<?php
final class LazyIterator implements \IteratorAggregate {
    private \Closure $iteratorFactory;
    public function __construct(callable $iteratorFactory) { $this->iteratorFactory = $iteratorFactory(...); }
    public function getIterator(): \Traversable { yield from ($this->iteratorFactory)(); }
}
final class Fnd implements \IteratorAggregate {
    private array $iterators = [];
    private array $dirs = [];
    public function in(array $dirs): static { foreach ($dirs as $d) { $this->dirs[] = $d; } return $this; }
    public function append(iterable $iterator): static { $this->iterators[] = $iterator; return $this; }
    public function getIterator(): \Iterator {
        $iterator = new \AppendIterator();
        foreach ($this->iterators as $it) {
            $iterator->append(new \IteratorIterator(new LazyIterator(static function () use ($it) {
                foreach ($it as $file) {
                    if (!$file instanceof \SplFileInfo) { $file = new \SplFileInfo($file); }
                    $key = $file->getPathname();
                    yield $key => $file;
                }
            })));
        }
        return $iterator;
    }
}
function finder(): iterable { return (new Fnd())->in([])->append([__FILE__, __DIR__ . '/../expected/yield_from_erased_lazy.out']); }
$a = iterator_to_array(finder());
var_dump(count($a));
foreach ($a as $k => $v) { echo basename($k), ' ', $v->getFilename(), "\n"; }
