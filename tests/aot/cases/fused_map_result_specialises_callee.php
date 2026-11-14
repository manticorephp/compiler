<?php
// array_map(fn) results feed a prelude function that is specialised per argument array type: ints, floats, bools, objects, string keys.

final class Site
{
    public function __construct(public int $col, public string $reason, public float $w, public bool $ok) {}
}
$s = [new Site(1, 'dm', 0.5, true), new Site(1, 'dm', 0.5, true), new Site(2, 'x', 1.5, false)];

var_dump(array_unique(array_map(fn($x) => $x->col, $s)));
var_dump(array_unique(array_map(fn($x) => $x->reason, $s)));
var_dump(array_unique(array_map(fn($x) => $x->w, $s)));
var_dump(array_unique(array_map(fn($x) => $x->ok, $s)));
var_dump(array_unique(array_map(fn($x) => $x->col, $s)) === [0 => 1, 2 => 2]);

$m = array_map(fn($z) => $z * 2, [1, 2, 2]);
var_dump(array_unique($m), array_sum($m), array_flip($m), array_reverse($m), in_array(4, $m, true));

$k = ['a' => 3, 'b' => 3, 'c' => 4];
var_dump(array_unique(array_map(fn($z) => $z + 1, $k)));
var_dump(array_count_values(array_map(fn($z) => $z + 1, $k)));
var_dump(array_unique(array_map(fn($z) => (string)$z, [1, 1, 2])));
