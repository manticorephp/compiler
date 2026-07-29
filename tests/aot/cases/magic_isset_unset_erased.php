<?php

// isset()/unset() on an erased receiver must reach __isset/__unset, not __get.
// The two are free to disagree, and php calls the one that matches the syntax.

class Ovl
{
    /** @var array<string,mixed> */
    private array $d = [];

    public function __get(string $k): mixed
    {
        echo "[get $k]\n";
        return $this->d[$k] ?? null;
    }

    public function __set(string $k, mixed $v): void
    {
        $this->d[$k] = $v;
    }

    public function __isset(string $k): bool
    {
        echo "[isset $k]\n";
        return isset($this->d[$k]);
    }

    public function __unset(string $k): void
    {
        echo "[unset $k]\n";
        unset($this->d[$k]);
    }
}

class Bare2
{
    public int $real = 5;
}

function erase(mixed $o): mixed
{
    return $o;
}

$o = new Ovl();
$o->a = 1;
$e = erase($o);

var_dump(isset($e->a));
var_dump(isset($e->nope));

unset($e->a);
var_dump(isset($e->a));

// A receiver with no magic at all: a declared slot is set, an undeclared name
// is not — and neither may run the other class's hook or fault on a bag read
// at an offset this class does not have.
$b = erase(new Bare2());
var_dump(isset($b->real));
var_dump(isset($b->nothing));
