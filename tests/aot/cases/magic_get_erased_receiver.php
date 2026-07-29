<?php

// __get / __set fire on a receiver whose class is ERASED (a `mixed` value, a
// foreach over a mixed array). The typed path has always routed an undeclared
// property through them; the erased one used to fall through to a bag read.

class Store
{
    /** @var array<string,mixed> */
    private array $data = [];
    public int $real = 1;

    public function __get(string $k): mixed
    {
        return $this->data[$k] ?? "miss:$k";
    }

    public function __set(string $k, mixed $v): void
    {
        $this->data[$k] = $v;
    }
}

class Bare
{
    public int $real = 2;
}

function erase(mixed $o): mixed
{
    return $o;
}

$s = new Store();
$s->x = 42;

// Typed receiver — the path that already worked.
var_dump($s->x);

$e = erase($s);
var_dump($e->real);      // a declared slot, through the erased receiver
var_dump($e->x);         // __get
var_dump($e->missing);   // __get, absent key
$e->y = 'set-erased';    // __set
var_dump($s->y);

// A class WITHOUT the magic method must still take its own slot, and must not
// be handed the other class's __get.
$b = erase(new Bare());
var_dump($b->real);

// Both flavours through one erased carrier.
/** @var array<int,mixed> $mixed */
$mixed = [$s, new Bare()];
foreach ($mixed as $it) {
    var_dump($it->real);
}
