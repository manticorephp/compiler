<?php
// json — json_encode loop (recursive box + string build).
$row = ["id" => 42, "name" => "widget", "price" => 9.5, "tags" => ["a", "b", "c"]];
$acc = 0;
for ($i = 0; $i < 200000; $i++) {
    $s = json_encode($row);
    $acc += strlen($s);
}
echo $acc, "\n";
