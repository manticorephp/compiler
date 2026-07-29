<?php

// php 7.4+: __unserialize() REPLACES the slot fill and is handed the array
// __serialize() produced, keys verbatim.

class Pt
{
    public int $x = 0;
    public int $y = 0;

    /** @return array<string,int> */
    public function __serialize(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }

    /** @param array<string,int> $data */
    public function __unserialize(array $data): void
    {
        $this->x = (int)$data['x'];
        $this->y = (int)$data['y'];
    }
}

class Derived extends Pt
{
    public int $ignored = 5;
}

class Renamed
{
    public string $now = '';

    /** @return array<string,string> */
    public function __serialize(): array
    {
        return ['then' => $this->now];
    }

    /** @param array<string,string> $data */
    public function __unserialize(array $data): void
    {
        $this->now = (string)$data['then'] . '!';
    }
}

$p = new Pt();
$p->x = 3;
$p->y = 4;
$s = serialize($p);
echo $s, "\n";
$p2 = unserialize($s);
var_dump(get_class($p2), $p2->x, $p2->y);

$d = new Derived();
$d->x = 1;
$d->y = 2;
$d2 = unserialize(serialize($d));
var_dump(get_class($d2), $d2->x, $d2->y, $d2->ignored);

$r = new Renamed();
$r->now = 'v';
echo serialize($r), "\n";
$r2 = unserialize(serialize($r));
var_dump($r2->now);

// Shared and repeated through the magic path.
$both = unserialize(serialize([$p, $p]));
var_dump($both[0] === $both[1], $both[0]->x);
