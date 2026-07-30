<?php

// php 7.4+: __serialize() REPLACES the property walk. Its keys go out verbatim
// (no visibility mangling) and may be int or string; the class name is still
// the object's own.

class Pt
{
    public function __construct(public int $x = 1, public int $y = 2)
    {
    }

    /** @return array<string,int> */
    public function __serialize(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }
}

class Seq
{
    public int $n = 3;

    /** @return int[] */
    public function __serialize(): array
    {
        return [$this->n, $this->n * 2];
    }
}

class Skipping
{
    public int $keep = 1;
    public string $secret = 'hidden';

    /** @return array<string,int> */
    public function __serialize(): array
    {
        return ['keep' => $this->keep];
    }
}

class Base
{
    public int $b = 1;

    /** @return array<string,int> */
    public function __serialize(): array
    {
        return ['b' => $this->b];
    }
}

class Derived extends Base
{
    public int $d = 2;
}

class Nest
{
    /** @return array<string,mixed> */
    public function __serialize(): array
    {
        return ['inner' => new Pt(9, 8), 'list' => [1, 2]];
    }
}

echo serialize(new Pt()), "\n";
echo serialize(new Seq()), "\n";
echo serialize(new Skipping()), "\n";
// Inherited: Derived declares none, so Base's runs and `d` never appears.
echo serialize(new Derived()), "\n";
echo serialize(new Nest()), "\n";

$p = new Pt(5, 6);
echo serialize([$p, $p]), "\n";
echo serialize(['a' => new Skipping(), 'b' => 7]), "\n";
