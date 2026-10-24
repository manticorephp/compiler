<?php
// Denormal floats through mixed cells: box, arithmetic, var_dump, json, array round trip, pointer probes.
function id(mixed $v): mixed { return $v; }
function probe(mixed $v): string {
    return implode(',', [var_export(is_resource($v), true), var_export(is_array($v), true),
        var_export($v instanceof stdClass, true), var_export(is_float($v), true), var_export(is_object($v), true)]);
}
$vals = [1e-310, 5e-324, 2.2250738585072014e-308, 1.4e-309, -1e-310, 2.5e-320, 2.0e-314];
foreach ($vals as $d) {
    $m = id($d);
    var_dump($m);
    echo probe($m), "\n";
    echo $m, " ", $m * 2, " ", $m + $m, " ", (float)$m === $d ? "same" : "diff", "\n";
    var_dump($m == $d, $m === $d, $m > 0, (bool)$m, (string)$m);
    $a = ['k' => $m, 3 => [$m]];
    var_dump($a['k'] === $d, $a[3][0] === $d);
    echo json_encode([$m]), " ", serialize($m), " ", var_export($m, true), "\n";
    var_dump(unserialize(serialize($m)) === $d);
    printf("%e\n", $m);
}
$list = [];
foreach ($vals as $d) { $list[] = id($d); }
var_dump($list === $vals, max($list), min($list), array_sum($list));
