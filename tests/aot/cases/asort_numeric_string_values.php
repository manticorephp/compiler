<?php
// asort/arsort/sort compare numeric strings as bytes, php compares them as numbers
$a = ["a" => "10", "b" => "9", "c" => "1e1"];
asort($a);
echo json_encode($a), "\n";
$l = ["10", "9", "1e1"];
sort($l);
echo json_encode($l), "\n";
