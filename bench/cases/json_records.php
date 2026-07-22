<?php
// json_records — encode a 10k-row list of small assoc records (API payload shape).
// Exercises: hashed-object walk, is_list pre-scan, Ryu float tail, int emit.
$rows = [];
for ($i = 0; $i < 10000; $i++) {
    $rows[] = ["id" => $i, "name" => "item" . $i, "price" => $i + 0.25, "qty" => $i % 7, "active" => ($i % 2) === 0];
}
$acc = 0;
$reps = 200 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $s = json_encode($rows);
    $acc += strlen($s);
}
echo $acc, "\n";
