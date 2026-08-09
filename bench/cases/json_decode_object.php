<?php
// json_decode_object — php's DEFAULT decode, which builds stdClass objects
// rather than assoc arrays. `$associative` is declared and ignored today (and
// defaults to true, not php's null), so the property reads below are the part
// that has never worked: a MISMATCH until decode parity lands.
$rows = [];
for ($i = 0; $i < 4000; $i++) {
    $rows[] = ["id" => $i, "name" => "widget " . $i, "price" => 9.5, "ok" => true];
}
$doc = json_encode($rows);
$acc = 0;
$reps = 20 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $v = json_decode($doc);
    $acc += count($v) + strlen($v[0]->name);
}
echo $acc, "\n";
