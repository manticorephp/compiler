<?php

// A by-ref out-parameter fed an UNDEFINED local, once per call shape. Each
// shape indexes the callee's by-ref mask differently — an instance method and
// a constructor carry `$this` at params[0] (+1), a static call does not (+0),
// a free function does not. Getting one offset wrong reads a NEIGHBOURING
// parameter's by-ref flag, so every shape needs its own witness.

function freeFill(int $a, ?array &$out): int
{
    $out = [$a, $a * 2];
    return $a;
}

class Sink
{
    public int $seen = 0;

    public function __construct(int $a, ?array &$out)
    {
        $out = ['ctor', $a];
        $this->seen = $a;
    }

    public function fill(int $a, ?array &$out): int
    {
        $out = ['method', $a];
        return $a + 1;
    }

    public static function fillStatic(int $a, ?array &$out): int
    {
        $out = ['static', $a];
        return $a + 2;
    }
}

echo freeFill(3, $fromFree), "\n";
var_dump($fromFree);

$s = new Sink(7, $fromCtor);
var_dump($fromCtor);
echo $s->seen, "\n";

echo $s->fill(10, $fromMethod), "\n";
var_dump($fromMethod);

echo Sink::fillStatic(20, $fromStatic), "\n";
var_dump($fromStatic);
