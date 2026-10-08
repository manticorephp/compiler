<?php
// A reified class whose binding changes a property's layout coerces an argument that does not fit the binding instead of keeping it.
// issue: #135
/** @template T */
final class Bag
{
    /** @var array<int, T> */
    private array $items = [];
    /** @param T $v */
    public function add(mixed $v): void { $this->items[] = $v; }
    public function kinds(): string { return implode(',', array_map('get_debug_type', $this->items)); }
}
/** @var Bag<float> $b */
$b = new Bag();
$b->add(1.5);
$b->add(5);
$b->add("7");
echo $b->kinds(), "\n";
