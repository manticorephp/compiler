<?php

#[TypeDef(repr: 'u8')]
final class U8
{
    public function __construct(public readonly int $value) {}

    public function add(U8 $other): U8
    {
        return new U8(($this->value + $other->value) & 0xFF);
    }

    public function raw(): int
    {
        return $this->value;
    }
}

#[TypeDef(repr: 'f64')]
final class Meters
{
    public function __construct(public readonly float $length) {}

    public function plus(Meters $other): Meters
    {
        return new Meters($this->length + $other->length);
    }

    public function double(): Meters
    {
        return new Meters($this->length * 2.0);
    }
}

function widen(U8 $byte): int
{
    return $byte->value + 1;
}

$a = new U8(200);
$b = new U8(100);
$c = $a->add($b);
echo $c->raw(), "\n";
echo $c->value, "\n";
echo widen($c), "\n";

$d = new Meters(1.5);
$e = $d->plus(new Meters(2.25));
echo $e->length, "\n";
echo $e->double()->length, "\n";
