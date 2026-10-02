<?php
// A cell-element array stored into a typed-array local is rebuilt unboxed; when
// the source is a named local that still releases its elements, the rebuild
// must take its own reference per element (it freed each one twice).
abstract class Node { public function __construct(public readonly string $kind) {} }
final class Leaf extends Node { public function __construct(public readonly string $name) { parent::__construct('leaf'); } }
final class Wrap extends Node { public function __construct(public readonly Node $inner) { parent::__construct('wrap'); } }

final class Expander
{
    /** @param Node[] $args */
    private function expand(string $fn, array $args): ?array
    {
        if (\count($args) !== 1 || $args[0]->kind !== 'wrap') { return null; }
        if ($fn === "none") { return [0]; }
        $inner = $args[0]->inner;
        $out = [];
        for ($i = 0; $i < 2; $i++) { $out[] = new Wrap($inner); }
        return $out;
    }

    public function total(string $fn, array $args): int
    {
        $e = $this->expand($fn, $args);
        if ($e !== null) { $args = $e; }
        $n = 0;
        foreach ($args as $a) { $n += \strlen($a->kind); }
        return $n;
    }
}

$x = new Expander();
$w = new Wrap(new Leaf('x'));
$args = [$w];
for ($k = 0; $k < 3; $k++) { echo $x->total("two", $args), "\n"; }
echo $w->inner->name, "\n";
