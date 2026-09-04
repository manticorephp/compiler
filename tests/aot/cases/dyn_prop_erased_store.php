<?php

/**
 * `$o->$name = v` on an ERASED receiver: the store dispatch is extracted into
 * one shared writer per property, so this checks the right holder's slot is
 * written, a `__set` holder still takes the write through the method, and a
 * real bag receives an undeclared name.
 */

class Alpha
{
    public int $id = 0;
    public string $label = '';
}

class Beta
{
    public int $id = 0;
    public int $count = 0;
}

class Overload
{
    /** @var array<string, mixed> */
    public array $seen = [];

    public function __set(string $n, mixed $v): void { $this->seen[$n] = $v; }
}

function writeDyn(mixed $o, string $f, mixed $v): void
{
    $o->$f = $v;
}

$a = new Alpha();
writeDyn($a, 'id', 7);
writeDyn($a, 'label', 'set');
echo 'a: ', $a->id, ' ', $a->label, "\n";

$b = new Beta();
writeDyn($b, 'id', 11);
writeDyn($b, 'count', 22);
echo 'b: ', $b->id, ' ', $b->count, "\n";

$o = new Overload();
writeDyn($o, 'id', 33);
echo 'overload: ', $o->seen['id'], "\n";

$bag = new stdClass();
writeDyn($bag, 'id', 44);
writeDyn($bag, 'fresh', 'new');
echo 'bag: ', $bag->id, ' ', $bag->fresh, "\n";
