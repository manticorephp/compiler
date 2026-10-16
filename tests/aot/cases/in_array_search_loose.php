<?php
/** @param string[] $h */
function s(array $h, string $n): string { return json_encode([in_array($n, $h), array_search($n, $h), in_array($n, $h, true), array_search($n, $h, true)]); }
function b(array $h, mixed $n): string { return json_encode([in_array($n, $h), array_search($n, $h), in_array($n, $h, true), array_search($n, $h, true)]); }
$h = ["abc", "10", "1e1", "x", "", " 5", "05", "0"];
foreach (["abc", "10", "1e1", "10.0", "5", "5 ", "zz", "", "0", "00", "1e1 ", "ABC"] as $n) { echo $n, ' ', s($h, $n), "\n"; }
$m = ["k1" => "a", "k2" => "1.0", 7 => "z"];
foreach (["a", "1", "1.0", "z", "q"] as $n) { echo $n, ' ', s($m, $n), "\n"; }
foreach ([[], ["a"]] as $e) { echo s($e, "a"), "\n"; }
$mix = [0, null, false, true, 1.5, "abc", "", "1", [1], 7];
foreach (["abc", "", "0", "1", "1.5", "7", 0, null, 7, 1.5, "x", true] as $n) { echo json_encode($n), ' ', b($mix, $n), "\n"; }
foreach ([0, 1, 2, 7, 8, 9, 17, 1000] as $c) {
    $a = []; for ($i = 0; $i < $c; $i++) { $a[] = "v$i"; }
    echo $c, ' ', s($a, "v" . ($c - 1)), ' ', s($a, "nope"), ' ', b($a, "v0"), "\n";
}
