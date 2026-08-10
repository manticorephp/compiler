<?php

// A reference that OWNS element refs gives them back on EVERY release, not only
// at rc -> 0 (__mir_array_release_ownel_*). What must stay true is that it gives
// back exactly ONE: an over-release shows up here as an emptied array or a
// clobbered string, never as a leak.

class Box
{
    /** @var array<string,string> */
    public array $map = [];
    /** @var string[] */
    public array $list = [];

    /** @param array<string,string> $m */
    public function setMap(array $m): void { $this->map = $m; }
    /** @param string[] $l */
    public function setList(array $l): void { $this->list = $l; }
}

/** @return array<string,string> */
function mkMap(int $n): array
{
    $m = [];
    for ($i = 0; $i < $n; $i++) { $m['k' . $i] = 'v' . $i; }
    return $m;
}

$b = new Box();

// The snapshot alias survives an overwrite of the property it came from.
$m = mkMap(3);
$b->setMap($m);
$snap = $b->map;
$b->setMap(mkMap(2));
echo implode(',', $snap), "|", implode(',', $b->map), "\n";

// Mutating a snapshot COWs: the source keeps its own elements.
$snap2 = $b->map;
$snap2['extra'] = 'x';
echo implode(',', $snap2), "|", implode(',', $b->map), "\n";

// Same for a vec property (the read COPIES, then owns).
$b->setList(['a', 'b', 'c']);
$l = $b->list;
$b->setList(['z']);
$l[] = 'd';
echo implode(',', $l), "|", implode(',', $b->list), "\n";

// Overwriting in a loop, reading the snapshot every round.
$acc = '';
for ($i = 0; $i < 4; $i++) {
    $cur = mkMap($i + 1);
    $b->setMap($cur);
    $s = $b->map;
    $acc = $acc . count($s) . ':' . implode('-', $s) . ' ';
}
echo trim($acc), "\n";

// A dynamic-property bag is handed out co-owned — exporting twice must print
// the same thing twice, nested array included.
$o = new stdClass();
$o->n = ['a' => 1];
$o->s = 'kept';
var_export($o);
echo "\n";
var_export($o);
echo "\n";
