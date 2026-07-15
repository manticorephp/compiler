<?php
// Dynamic-callback: usort's internal $cmp invoke is made KNOWN by monomorphizing
// usort per the concrete closure argument, so the pair array reaches the closure
// param cellified and $a["k"] boxes correctly when passed to $cmp.
$cmp = fn($x, $y) => $x - $y;

$rows = [
    ["k" => 3, "name" => "c"],
    ["k" => 1, "name" => "a"],
    ["k" => 2, "name" => "b"],
];

usort($rows, fn($a, $b) => $cmp($a["k"], $b["k"]));

foreach ($rows as $r) {
    echo $r["k"], ":", $r["name"], "\n";
}
