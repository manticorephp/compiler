<?php
// An array<string, mixed> built from json_decode() compares unequal (== and ===) to the same literal array when a value is itself an array
// issue: #366
/** @return array<string, mixed> */
function g(string $s): array
{
    $j = json_decode($s, true);
    /** @var array<string, mixed> $out */
    $out = [];
    foreach ($j as $k => $v) {
        $out[(string) $k] = $v;
    }
    return $out;
}
var_dump(g('{"a":{"b":1}}') === ['a' => ['b' => 1]]);
var_dump(g('{"a":{"b":1}}') == ['a' => ['b' => 1]]);
echo json_encode(g('{"a":{"b":1}}')), "\n";
