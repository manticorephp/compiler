<?php
// `iterable $o` + `@param iterable<T> $o` keeps the hint's cell type: the doc form
// lowered to unknown and the iterator_to_array arm broke the slot
// (php-cs-fixer FixerOptionSorter).
interface Opt { public function getName(): string; }
final class O implements Opt { public function __construct(private string $n) {} public function getName(): string { return $this->n; } }
final class Sorter
{
    /** @param iterable<Opt> $options @return list<Opt> */
    public function sort(iterable $options): array
    {
        if (!\is_array($options)) { $options = iterator_to_array($options, false); }
        usort($options, static fn (Opt $a, Opt $b): int => $a->getName() <=> $b->getName());
        return $options;
    }
}
final class Res
{
    private array $options;
    public function __construct(iterable $options)
    {
        $this->options = (new Sorter())->sort($options);
        if (0 === \count($this->options)) { throw new \LogicException('empty'); }
    }
    public function names(): string { $o = []; foreach ($this->options as $x) { $o[] = $x->getName(); } return count($this->options) . ':' . implode(',', $o); }
}
echo (new Res([new O('b'), new O('a'), new O('c')]))->names(), "\n";
function gen() { yield new O('z'); yield new O('y'); }
echo (new Res(gen()))->names(), "\n";
