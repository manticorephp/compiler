<?php
// A cast is a consumer of a fresh CELL temp: `(string)json_encode($v)` has to
// drop the document it just read, and the string it hands back is a +1 of its
// own. Both halves at once — a missing release leaks, an extra one frees the
// buffer under the reader below.

$rows = [];
for ($i = 0; $i < 4; $i++) {
    $s = "row" . $i;
    $enc = (string)json_encode(["k" => $s, "n" => $i]);
    $rows[] = $enc;
    echo strlen($enc), " ", $enc, "\n";
}
foreach ($rows as $r) { echo $r, "\n"; }

// The int / float arms of the same cast: a scalar cannot reference the cell.
$n = 0;
$f = 0.0;
for ($i = 0; $i < 4; $i++) {
    $n = $n + (int)json_decode((string)json_encode($i));
    $f = $f + (float)json_decode((string)json_encode($i * 2));
}
echo $n, " ", $f, "\n";

// A cast whose operand is already a string is a BORROW — owning it would free
// the source out from under the array that still holds it.
$keep = [];
for ($i = 0; $i < 3; $i++) {
    $t = "s" . $i;
    $keep[] = (string)$t;
    echo $t, "\n";
}
echo implode(",", $keep), "\n";
