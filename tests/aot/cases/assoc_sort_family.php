<?php
// ksort/krsort/asort/arsort over string AND int keys/values, plus a bare
// tagged cell ordering (array_keys elements compared) — exercises the runtime
// tag-dispatched comparison (__manticore_tagged_compare).
$m = ["banana" => 3, "apple" => 1, "cherry" => 2];
ksort($m);  foreach ($m as $k => $v) { echo "$k$v"; } echo "\n";
krsort($m); foreach ($m as $k => $v) { echo "$k$v"; } echo "\n";
asort($m);  foreach ($m as $k => $v) { echo "$k$v"; } echo "\n";
arsort($m); foreach ($m as $k => $v) { echo "$k$v"; } echo "\n";

$s = ["z" => "zebra", "a" => "ant", "m" => "mouse"];
asort($s);  foreach ($s as $k => $v) { echo "$k:$v "; } echo "\n";

$i = [7 => 1, 3 => 1, 9 => 1, 1 => 1];
ksort($i);  echo implode(",", array_keys($i)), "\n";
