<?php
interface I {} class P implements I {} final class B extends P {}
class Def { public function __construct(private mixed $c) {} public function getClass(): mixed { return $this->c; } }
class Bag { public function resolveValue(mixed $v): mixed { return $v; } }
function process(Def $d, array $conds, Bag $bag) {
    if (!$class = $bag->resolveValue($d->getClass())) { return 'none'; }
    $out = [];
    foreach ($conds as $interface => $defs) {
        if ($interface !== $class && !is_subclass_of($class, $interface)) { $out[] = "skip $interface"; continue; }
        $out[] = "match $interface";
    }
    return implode(',', $out);
}
echo process(new Def('B'), ['I' => [1], 'Z' => [2], 'P' => [3]], new Bag), "\n";
