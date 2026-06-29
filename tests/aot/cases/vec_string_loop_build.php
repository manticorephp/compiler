<?php
// A vec built by appending typed call results in a loop keeps its
// element type across the loop back-edge (vec[string], not vec[unknown]),
// so reads echo as strings — not the raw pointer as an int.
function label(int $n): string { return "row-" . $n; }
function rows(int $k): array {
    $out = [];
    $i = 0;
    while ($i < $k) { $out[] = label($i); $i = $i + 1; }
    return $out;
}
$v = rows(3);
echo $v[0], "|", $v[1], "|", $v[2], "\n";
echo count($v), "\n";
