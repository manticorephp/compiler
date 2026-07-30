<?php
// An ERASED carrier that holds a TRAVERSABLE OBJECT. `foreach` over a `mixed` /
// `iterable` carrier classified "array or generator" and answered the EMPTY
// array for anything else, so an IteratorAggregate iterated ZERO times —
// silently. symfony's Table hands `calculateColumnsWidth(iterable $groups)` a
// TableRows (an IteratorAggregate), so every column measured 0 and the table
// drew its borders around correctly rendered rows.
//
// Also covers the subscript side: an erased base may hold a boxed ARRAY cell
// (a generator's `current`), which the raw inttoptr read as an address —
// `isset($row[$c])` answered false for every cell.

class Rows implements IteratorAggregate
{
    private array $data;

    public function __construct(array $d) { $this->data = $d; }

    public function getIterator(): Traversable
    {
        foreach ($this->data as $k => $v) { yield $k => $v; }
    }
}

class Counter implements Iterator
{
    private int $i = 0;
    private array $d;

    public function __construct(array $d) { $this->d = $d; }

    public function current(): mixed { return $this->d[$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i = $this->i + 1; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->d); }
}

function joinGroups(iterable $groups): string
{
    $out = '';
    foreach ($groups as $g) { $out = $out . '[' . implode(',', $g) . ']'; }
    return $out;
}

echo joinGroups(new Rows([['a', 'b'], ['c']])), "\n";
echo joinGroups(new Counter([['x', 'y'], ['z']])), "\n";
echo joinGroups([['p', 'q']]), "\n";

function gen(): Generator { yield ['g1', 'g2']; }
echo joinGroups(gen()), "\n";

// Values survive the aggregate protocol with the keys in play. (The KEY of an
// INTERFACE-typed iterator is still typed `unknown` and prints as its raw
// carrier — a separate, pre-existing gap; assert the values only.)
foreach (new Rows(['first' => ['a'], 'second' => ['b']]) as $k => $v) {
    echo implode('', $v), ' ';
}
echo "\n";

// An erased row read by subscript: `isset` then the element itself.
function cellWidth($row, int $column): int
{
    $w = 0;
    if (isset($row[$column])) { $w = strlen($row[$column]); }
    return $w;
}

$agg = new Rows([['hammer', '12', 'tools']]);
foreach ($agg as $r) {
    echo cellWidth($r, 0), ',', cellWidth($r, 1), ',', cellWidth($r, 2), ',', cellWidth($r, 9), "\n";
}

// An erased carrier that is NOT traversable still yields nothing, as before.
function countAny($x): int { $n = 0; foreach ($x as $v) { $n = $n + 1; } return $n; }
echo countAny([1, 2, 3]), countAny([]), "\n";
echo "done\n";
