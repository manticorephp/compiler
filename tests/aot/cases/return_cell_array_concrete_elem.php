<?php
// An array whose elements are cells (an `iterable` / `mixed` value) returned
// under a concrete element claim (`@return list<Opt>`) reaches the caller with
// raw elements — php-cs-fixer's FixerOptionSorter::sort.

interface Opt { public function getName(): string; }
final class O implements Opt { public function __construct(private string $n) {} public function getName(): string { return $this->n; } }

final class Sorter
{
    /**
     * @param iterable<Opt> $options
     * @return list<Opt>
     */
    public function sort(iterable $options): array
    {
        if (!\is_array($options)) { $options = iterator_to_array($options, false); }
        usort($options, static fn (Opt $a, Opt $b): int => $a->getName() <=> $b->getName());
        return $options;
    }

    /** @return list<int> */
    public function ints(mixed $m): array { return $m; }
}

final class Resolver
{
    /** @var list<Opt> */
    private array $options;
    /** @param iterable<Opt> $options */
    public function __construct(iterable $options) { $this->options = (new Sorter())->sort($options); }
    /** @return list<Opt> */
    public function getOptions(): array { return $this->options; }
}

$r = new Resolver([new O('b'), new O('a'), new O('c')]);
$all = [...$r->getOptions(), new O('z')];
foreach ($all as $o) { echo $o->getName(); }
echo "\n";
$gen = (static function () { yield new O('y'); yield new O('x'); })();
foreach ((new Sorter())->sort($gen) as $o) { echo $o->getName(); }
echo "\n", array_sum((new Sorter())->ints([1, 2, 3])), "\n";
