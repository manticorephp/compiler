<?php
function fill(mixed $g): mixed {
    for ($i = 0; $i < 3; $i++) { $g[$i] = $i * 70000; }
    $g['s'] = "str";
    return $g;
}
var_dump(fill([]));
var_dump(fill(['pre' => 1]));
$c = 70000;
function fillc(mixed $g, mixed $c): mixed { $g[0] = $c; $g[1] = $c + 1; return $g; }
var_dump(fillc([], $c));
var_dump(fillc([], 65536));
