<?php
interface Aware extends \Iterator { public function res(): ?string; }
final class LIt extends \IteratorIterator implements Aware {
    private ?string $cur = null;
    public function __construct(\Traversable $it) { parent::__construct($it); }
    public function res(): ?string { return $this->cur; }
    public function next(): void { parent::next(); $this->cur = $this->valid() ? 'R' . $this->current() : null; }
    public function rewind(): void { parent::rewind(); $this->cur = $this->valid() ? 'R' . $this->current() : null; }
}
function coll(): Aware { return new LIt(new \ArrayIterator(['a', 'b'])); }
$c = coll();
foreach ($c as $f) { echo $f, ':', var_export($c->res(), true), ' '; }
echo "\n";
$d = new LIt(new \ArrayIterator(['x']));
foreach ($d as $f) { echo $f, ':', var_export($d->res(), true), ' '; }
echo "\n";
