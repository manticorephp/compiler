<?php
// json_decode — the parse side. The encode case above it is dominated by
// building the row array, so it hid the decoder entirely: a ~750 KB document
// is built once, then decoded repeatedly and only the decode is on the clock.
$rows = [];
for ($i = 0; $i < 8000; $i++) {
    $rows[] = [
        "id" => $i,
        "name" => "widget number $i",
        "price" => 9.5,
        "tags" => ["alpha", "beta", "gamma"],
        "ok" => true,
    ];
}
$doc = json_encode($rows);

$acc = 0;
for ($r = 0; $r < 40; $r++) {
    $v = json_decode($doc, true);
    $acc += count($v);
}
echo $acc, "\n";
