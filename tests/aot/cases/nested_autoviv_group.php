<?php
// grouping idiom: $by[$key][] = $val (was a pre-existing SIGSEGV)
$rows = [["cat" => "a", "v" => 1], ["cat" => "b", "v" => 2], ["cat" => "a", "v" => 3]];
$by = [];
foreach ($rows as $r) {
    $by[$r["cat"]][] = $r["v"];
}
var_dump($by);
