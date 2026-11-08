<?php
// [...$v] drains a Traversable whatever the operand's static type: a mixed, a property, a union (symfony/ux-icons over DOMNamedNodeMap).
class It implements IteratorAggregate { public function getIterator(): Iterator { return new ArrayIterator(['a' => 1, 'b' => 2]); } }
class L implements Iterator { private int $i = 0; public function current(): mixed { return $this->i * 10; } public function key(): mixed { return $this->i; } public function next(): void { $this->i++; } public function rewind(): void { $this->i = 0; } public function valid(): bool { return $this->i < 2; } }
function gen() { yield 'x'; yield 'y'; }
function kg() { yield 'k' => 1; yield 5 => 2; }
function er(mixed $v): array { return [...$v]; }
function tr(Traversable $v): array { return [0, ...$v, 9]; }
class H { public $p; public array $arr = [1]; public mixed $m = null; }
$h = new H; $h->p = new It; $h->m = new ArrayIterator([7, 8]);
echo json_encode([...new It]), json_encode([...new ArrayIterator([1, 2])]), json_encode([...gen()]), json_encode([...kg()]), json_encode([...new L]), "\n";
echo json_encode(er(new It)), json_encode(er([1, 'k' => 2])), json_encode(er(gen())), json_encode(tr(new L)), json_encode(tr(gen())), "\n";
echo json_encode([...$h->p]), json_encode([...$h->m]), json_encode([...$h->arr]), "\n";
$d = new DOMDocument; $d->loadXML('<a x="1" y="2"><b/></a>');
echo json_encode(array_map(static fn (DOMAttr $a) => $a->value, [...$d->documentElement->attributes])), count([...$d->documentElement->childNodes]), "\n";
try { er(5); } catch (Error $e) { echo get_class($e), ':', $e->getMessage(), "\n"; }
