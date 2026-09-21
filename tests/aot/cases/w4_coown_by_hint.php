<?php
// Two owners of ONE buffer with DIFFERENT static element flavors: the
// `string[]` producer and a bare-`array` holder whose other call site feeds it
// boxed cells. Co-own and drop must both read the buffer's element HINT — a
// cell-flavored walk over raw string pointers co-owns nothing, the string
// flavored release then frees the strings under the holder (the compiler's
// doc comments died under `Program` this way; a Token reused the block).
final class Bag
{
    public function __construct(public readonly array $items = []) {}
}

final class Source
{
    /** @var string[] */
    private array $docs = [];

    public function add(string $s): void { $this->docs[] = $s; }

    public function bag(): Bag { return new Bag($this->docs); }
}

function fromStrings(int $n): Bag
{
    $src = new Source();
    for ($i = 0; $i < $n; $i++) { $src->add(str_repeat(chr(97 + $i % 26), 12 + $i)); }
    return $src->bag();
}

function fromCells(): Bag
{
    $cells = [];
    foreach ([1, 'x', 2.5, null, [1, 2]] as $v) { $cells[] = $v; }
    return new Bag($cells);
}

$b = fromStrings(6);
$c = fromCells();
// Churn the allocator: a block freed under $b would be handed out here.
$junk = [];
for ($i = 0; $i < 3000; $i++) { $junk[] = str_repeat('z', 12 + ($i % 8)); }
foreach ($b->items as $k => $d) { echo $k, ' ', $d, "\n"; }
echo count($c->items), ' ', json_encode($c->items), "\n";
$junk = null;
foreach ($b->items as $d) { echo strlen($d), ' '; }
echo "\n";
