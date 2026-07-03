<?php
// `??` on a missing/typed array key must render the chosen arm correctly
// (the fallback used to print its pointer as an int).
$m = ["x" => 1, "s" => "hi"];
echo ($m["y"] ?? "DEF"), "\n";          // missing int-elem  -> string default
echo ($m["x"] ?? "DEF"), "\n";          // present int
echo ($m["s"] ?? "DEF"), "\n";          // present string
echo ($m["z"] ?? 99), "\n";             // missing -> int default
$chain = $m["a"] ?? $m["b"] ?? "none";  // chained
echo $chain, "\n";
echo ($m["a"] ?? $m["s"] ?? "none"), "\n";
var_dump($m["y"] ?? "DEF");
var_dump($m["x"] ?? "DEF");
$vec = [10, 20];
echo ($vec[5] ?? 77), "\n";
$nested = ["u" => ["name" => "bob"]];
echo ($nested["v"]["name"] ?? "anon"), "\n";
$m["w"] ??= "added";
echo $m["w"], "\n";
