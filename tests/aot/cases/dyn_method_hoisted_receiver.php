<?php

// `$this->inner->{$m}(...$args)`: a receiver that is a property read, not a
// local. It is evaluated once into a slot so the call takes the shared table
// path; results must be php's, including a by-ref method elsewhere in the
// program (which keeps its own exact inline arm) and an element receiver.

interface Svc
{
    public function add(int $a, int $b): int;
}

class Impl implements Svc
{
    public int $calls = 0;
    public function add(int $a, int $b): int { $this->calls++; return $a + $b; }
    public function greet(string $who, string $how = 'hi'): string { $this->calls++; return "$how $who"; }
    public function bump(int &$x): void { $x++; }
    public function m01(): int { return 1; } public function m02(): int { return 2; }
    public function m03(): int { return 3; } public function m04(): int { return 4; }
    public function m05(): int { return 5; } public function m06(): int { return 6; }
    public function m07(): int { return 7; } public function m08(): int { return 8; }
    public function m09(): int { return 9; } public function m10(): int { return 10; }
    public function m11(): int { return 11; } public function m12(): int { return 12; }
    public function m13(): int { return 13; } public function m14(): int { return 14; }
}

final class Proxy
{
    /** @var Impl[] */
    public array $pool = [];
    public function __construct(public Svc $inner) { $this->pool[] = new Impl(); }

    /** @param mixed[] $args */
    public function __call(string $method, array $args): mixed
    {
        return $this->inner->{$method}(...$args);
    }

    public function viaPool(string $method): mixed
    {
        return $this->pool[0]->{$method}();
    }
}

$p = new Proxy(new Impl());
echo $p->add(2, 3), "\n";
echo $p->greet('bob'), "\n";
echo $p->greet('amy', 'yo'), "\n";
$n = 0;
for ($i = 1; $i <= 14; $i++) { $n += $p->{\sprintf('m%02d', $i)}(); }
echo $n, "\n";
echo $p->viaPool('m07'), "\n";
$v = 41;
$p->inner->bump($v);
echo $v, " ", $p->inner instanceof Impl ? $p->inner->calls : -1, "\n";
