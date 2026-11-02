<?php
// A binding is a claim over the layout: an argument that does not fit it throws.
/** @template T */
final class Bag
{
    /** @var array<int, T> */
    private array $items = [];
    /** @param T $v */
    public function add(mixed $v): void { $this->items[] = $v; }
    public function count(): int { return count($this->items); }
}
/** @template T */
final class Box
{
    public string $kind;
    /** @param T $v */
    public function __construct(mixed $v) { $this->kind = get_debug_type($v); }
}
/** @var Bag<float> $b */
$b = new Bag();
$b->add(1.5);
foreach ([5, "7"] as $bad) {
    try { $b->add($bad); echo "kept\n"; } catch (TypeError $e) { echo "TypeError\n"; }
}
try { $b->add(5); echo "kept\n"; } catch (TypeError $e) { echo "TypeError\n"; }
echo $b->count(), "\n";
/** @var Box<string> $c */
$c = new Box("ok");
echo $c->kind, "\n";
try {
    /** @var Box<string> $d */
    $d = new Box(5);
    echo "kept\n";
} catch (TypeError $e) { echo "TypeError\n"; }
