<?php
function g() { $a = [10, 20, 30]; foreach ($a as $v) { yield $v; } }
foreach (g() as $x) { echo $x, " "; } echo "\n";

function inner() { yield 1; yield 2; }
function outer() { yield 0; yield from inner(); yield 3; }
foreach (outer() as $v) { echo $v, " "; } echo "\n";

function fromArr() { yield from [7, 8, 9]; }
foreach (fromArr() as $v) { echo $v, " "; } echo "\n";

function kv() { yield from ["a" => 1, "b" => 2]; }
foreach (kv() as $k => $v) { echo $k, "=", $v, " "; } echo "\n";

function strs() { foreach (["x", "y", "z"] as $s) { yield $s . "!"; } }
foreach (strs() as $s) { echo $s, " "; } echo "\n";
