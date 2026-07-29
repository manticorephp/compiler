<?php

// __call fires on an erased receiver when NO class declares the method: every
// possible runtime receiver answers through __call, and the rewritten call's
// own virtual dispatch picks the right declarer.

class Facade
{
    public string $tag = 'one';

    /** @param array<int,mixed> $args */
    public function __call(string $m, array $args): mixed
    {
        return $this->tag . ':' . $m . '(' . (string)count($args) . ')';
    }
}

class Other
{
    public string $tag = 'two';

    /** @param array<int,mixed> $args */
    public function __call(string $m, array $args): mixed
    {
        return 'other:' . $m;
    }
}

function erase(mixed $o): mixed
{
    return $o;
}

$f = new Facade();

// Typed receiver — the path that already worked.
var_dump($f->anything(1, 2));

$e = erase($f);
var_dump($e->anything(1, 2));
var_dump($e->other());

// A different declarer through the same erased channel: the dispatch is on the
// runtime class, not on which class happens to be first in the table.
$g = erase(new Other());
var_dump($g->zzz());

/** @var array<int,mixed> $both */
$both = [$f, new Other()];
foreach ($both as $it) {
    var_dump($it->ping('x'));
}
