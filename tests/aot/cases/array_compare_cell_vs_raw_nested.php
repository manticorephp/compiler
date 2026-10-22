<?php
// An erased (cell-element) array compared with a raw-typed one whose elements
// are themselves arrays: every operator descends into the nested pair.

/** @return array<string, mixed> */
function decoded(string $s): array
{
    $j = json_decode($s, true);
    /** @var array<string, mixed> $out */
    $out = [];
    foreach ($j as $k => $v) {
        $out[(string) $k] = $v;
    }
    return $out;
}

$m = decoded('{"a":{"b":1,"c":2},"d":{"b":3,"c":4}}');
var_dump($m === ['a' => ['b' => 1, 'c' => 2], 'd' => ['b' => 3, 'c' => 4]]);
var_dump($m == ['a' => ['b' => 1, 'c' => 2], 'd' => ['b' => 3, 'c' => 4]]);
var_dump($m !== ['a' => ['b' => 1, 'c' => 2], 'd' => ['b' => 3, 'c' => 4]]);
var_dump($m != ['a' => ['b' => 1, 'c' => 2], 'd' => ['b' => 3, 'c' => 4]]);
var_dump($m === ['d' => ['b' => 3, 'c' => 4], 'a' => ['c' => 2, 'b' => 1]]);
var_dump($m == ['d' => ['b' => 3, 'c' => 4], 'a' => ['c' => 2, 'b' => 1]]);
var_dump($m === ['a' => ['b' => 1, 'c' => 2], 'd' => ['b' => 3, 'c' => 5]]);
var_dump($m == ['a' => ['b' => 1, 'c' => 2], 'd' => ['b' => 3, 'c' => 5]]);
var_dump($m <=> ['a' => ['b' => 1, 'c' => 2], 'd' => ['b' => 3, 'c' => 5]]);
var_dump($m <=> ['a' => ['b' => 1, 'c' => 2], 'd' => ['b' => 3, 'c' => 4]]);
var_dump($m < ['a' => ['b' => 1, 'c' => 2], 'd' => ['b' => 3, 'c' => 5]]);
var_dump(['a' => ['b' => 1, 'c' => 2], 'd' => ['b' => 3, 'c' => 4]] === $m);
var_dump($m == ['a' => ['b' => '1', 'c' => 2], 'd' => ['b' => '3', 'c' => 4]]);
var_dump($m === ['a' => ['b' => '1', 'c' => 2], 'd' => ['b' => '3', 'c' => 4]]);

/** @var list<mixed> $rows */
$rows = json_decode('[[[1,2],[3]],[[4]]]', true);
var_dump($rows === [[[1, 2], [3]], [[4]]]);
var_dump($rows == [[[1, 2], [3]], [[4]]]);
var_dump($rows === [[[1, 2], [3]], [[5]]]);
var_dump($rows <=> [[[1, 2], [3]], [[5]]]);
