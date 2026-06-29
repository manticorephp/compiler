<?php
function show($a) { $p = []; foreach ($a as $k => $v) { $p[] = $k . "=" . $v; } echo implode(",", $p), "\n"; }
function vals($a) { echo implode(",", $a), "\n"; }   // key-insensitive (filter renumbers int keys, as the prelude does)

$a = [1, 2, 3, 4, 5];
show(array_map(fn($x) => $x * $x, $a));            // map preserves keys
vals(array_filter($a, fn($x) => $x % 2 === 0));    // filter values
echo array_reduce($a, fn($c, $x) => $c + $x, 0), "\n";        // reduce int
echo array_reduce($a, fn($c, $x) => $c . "-" . $x, "z"), "\n"; // reduce string

$m = ["a" => 1, "b" => 2];
show(array_map(fn($x) => $x + 10, $m));            // assoc string keys preserved

$k = 100;                                          // captured -> falls back, still correct
show(array_map(fn($x) => $x + $k, $a));

show(array_map(fn($w) => strtoupper($w), ["foo", "bar"])); // string map (builtin in body)

show(array_map(fn($x) => $x * 1.5, [1.0, 2.0, 4.0]));        // float map
echo array_reduce([0.5, 0.25, 0.125], fn($c, $x) => $c + $x, 0.0), "\n"; // float reduce

vals(array_map(fn($x) => $x + 1, array_filter($a, fn($x) => $x > 2))); // chained values

$cb = fn($x) => $x * 10;                            // non-literal -> prelude path
vals(array_map($cb, [1, 2]));
