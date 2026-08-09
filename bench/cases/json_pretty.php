<?php
// json_pretty — JSON_PRETTY_PRINT. Two costs in one case: the flag is dropped
// (a MISMATCH against php), and the second argument takes the call off the
// native path entirely onto the compiled-PHP walker, whose escaper concatenates
// one heap string per byte.
$rows = [];
for ($i = 0; $i < 2000; $i++) {
    $rows[] = ["id" => $i, "name" => "item" . $i, "tags" => ["a", "b"], "price" => $i + 0.5];
}
$acc = 0;
$reps = 50 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $s = json_encode($rows, JSON_PRETTY_PRINT);
    $acc += strlen($s);
}
echo $acc, "\n";
