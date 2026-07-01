<?php
// json — json_encode loop. A field is mutated each iteration so the encoded
// value varies (can't be hoisted).
$acc = 0;
for ($i = 0; $i < 300000; $i++) {
    $row = ["id" => $i, "name" => "widget", "price" => 9.5, "tags" => ["a", "b", "c"]];
    $s = json_encode($row);
    $acc += strlen($s);
}
echo $acc, "\n";
