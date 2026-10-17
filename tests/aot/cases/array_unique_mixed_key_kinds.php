<?php
// array_unique over an array with both string and int keys prints the string keys as addresses
echo json_encode(array_unique(["x" => "p", "y" => "q", "z" => "p", 5 => "q"])), "\n";
$m = ["x" => "p", "y" => "q", "z" => "p", 5 => "q", 7 => "r"];
foreach ([0, 1, 2, 5] as $fl) { echo json_encode(array_unique($m, $fl)), "\n"; }
echo json_encode(array_unique(["a" => 1, "b" => "1", 3 => 1, "c" => 2])), "\n";
// a bare `array` param whose body reads its elements as strings keeps its keys
function skipZz(array $arr): array {
    $out = [];
    foreach ($arr as $k => $v) {
        if ($v === "zz") { continue; }
        $out[$k] = $v;
    }
    return $out;
}
echo json_encode(skipZz(["x" => "p", 5 => "q", "y" => "zz", 9 => "zz"])), "\n";
echo json_encode(skipZz(["a", "zz", "b"])), "\n";
echo json_encode(skipZz(["a" => "m", "b" => "zz"])), "\n";
function keysOf(array $arr): string {
    $s = '';
    foreach ($arr as $k => $v) { $s .= $k . '=' . strtoupper($v) . ';'; }
    return $s;
}
echo keysOf(["x" => "p", 5 => "q"]), keysOf(["a", "b"]), keysOf(["k" => "v"]), "\n";
