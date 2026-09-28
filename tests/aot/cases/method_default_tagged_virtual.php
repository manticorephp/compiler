<?php
// An omitted default of a tagged (mixed / nullable scalar / union) parameter, on a call the
// lowering cannot resolve to one class (the receiver has a subclass), is padded at emission:
// it has to be NaN-boxed like a written argument. A raw `null` default was word 0 — float(0).

class Base
{
    public function probe(?int $a = null, mixed $b = null, int|string $c = "s", ?string $d = null, mixed $e = 7, ?float $f = 1.5): void
    {
        var_dump($a, $b, $c, $d, $e, $f);
    }
}

class Child extends Base {}

function pick(int $n): Base
{
    return $n > 1 ? new Base() : new Child();
}

pick(1)->probe();
pick(2)->probe(3);
pick(1)->probe(3, "b", 9);
