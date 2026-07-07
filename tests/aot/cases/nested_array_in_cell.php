<?php
// Nested arrays boxed into a heterogeneous (cell) array must be released
// (tag7 → __mir_array_release_cell) — they used to leak. A borrowed cell-array
// is co-owned (retainCellPayload) so it survives + isn't double-freed.
function mk(int $i): array {
    $a = [$i, "s" . $i, $i * 2];
    $b = ["k" => $i + 10, "t" => "x" . $i];
    return [$a, "outer", $b];
}
$t = 0;
for ($i = 0; $i < 4; $i++) {
    $m = mk($i);
    $t += $m[0][0] + $m[2]["k"];
    echo $m[0][1], "/", $m[2]["t"], "\n";
}
echo $t, "\n";
$cm = [1, "two", 3.0];
$outer = [$cm, "z"];
$alias = $cm;
echo $outer[0][1], " ", $cm[0], " ", $alias[2], "\n";
