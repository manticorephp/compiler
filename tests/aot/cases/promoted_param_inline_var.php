<?php
// A promoted constructor param's own `@var` types the param and the property (#688).

final class Item
{
    public function __construct(public string $name, public int $qty) {}
    public function label(): string { return $this->name . 'x' . $this->qty; }
}

final class Bag
{
    public string $head = '';

    public function __construct(
        /** @var array<string, Item> */
        public array $items = [],
        /** @var list<int> $counts */
        private array $counts = [],
        #[\SensitiveParameter]
        /** @var Item[] */
        public readonly array $extra = [],
    ) {
        foreach ($items as $it) { $this->head = $it->label(); break; }
    }

    public function total(): int
    {
        $t = 0;
        foreach ($this->items as $it) { $t += $it->qty; }
        foreach ($this->counts as $c) { $t += $c; }
        return $t;
    }
}

final class Doc
{
    /** @param array<int, Item> $items */
    public function __construct(
        /** @var string[] */
        public array $items,
    ) {}
}

function loose(): array { return unserialize(serialize(['z' => new Item('plum', 5)])); }

$b = new Bag(['a' => new Item('apple', 2), 'b' => new Item('pear', 3)], [10, 20], [new Item('fig', 1)]);
echo $b->head, "\n";
foreach ($b->items as $k => $it) { echo $k, ': ', $it->label(), "\n"; }
echo $b->items['b']->label(), "\n";
echo $b->extra[0]->label(), "\n";
echo $b->total(), "\n";
$d = new Doc([new Item('kiwi', 4)]);
echo $d->items[0]->label(), "\n";
$l = new Bag(loose());
echo $l->head, ' ', $l->items['z']->label(), "\n";
