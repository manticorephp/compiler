<?php

/**
 * `$o->$m($arg)` on an ERASED receiver — an argument-bearing dynamic method
 * call, the shape `prelude/errors.php`'s handler invokers use. The dispatch is
 * extracted into one shared helper per (name, arg types), so this checks the
 * right override runs, arguments arrive in order, and a cell argument still
 * reaches a typed parameter.
 */

class Base
{
    public function greet(string $who, int $n): string { return 'base ' . $who . (string)$n; }
    public function solo(mixed $e): string { return 'base solo ' . (string)$e; }
}

class Child extends Base
{
    public function greet(string $who, int $n): string { return 'child ' . $who . (string)$n; }
}

class Other
{
    public function greet(string $who, int $n): string { return 'other ' . $who . (string)$n; }
    public function solo(mixed $e): string { return 'other solo ' . (string)$e; }
}

function call2(mixed $cb, string $a, int $b): mixed
{
    $o = $cb[0];
    $m = $cb[1];
    return $o->$m($a, $b);
}

function call1(mixed $cb, mixed $e): mixed
{
    $o = $cb[0];
    $m = $cb[1];
    return $o->$m($e);
}

foreach ([new Base(), new Child(), new Other()] as $o) {
    echo call2([$o, 'greet'], 'x', 7), "\n";
}
echo call1([new Base(), 'solo'], 42), "\n";
echo call1([new Other(), 'solo'], 'str'), "\n";
