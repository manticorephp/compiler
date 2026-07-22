<?php
// json_decode_records — decode a records payload repeatedly (assoc arrays).
// Exercises: per-object PACKED→HASHED promotion, geometric grow, set_str per key,
// escape decoding, number parsing.
$rows = [];
for ($i = 0; $i < 5000; $i++) {
    $rows[] = ["id" => $i, "name" => "item" . $i, "price" => $i + 0.25, "tags" => ["a", "b"], "note" => "line\nbreak " . $i];
}
$doc = json_encode($rows);
$sum = 0;
$reps = 40 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $d = json_decode($doc, true);
    $sum += count($d) + $d[4999]["id"];
}
echo $sum, "\n";
