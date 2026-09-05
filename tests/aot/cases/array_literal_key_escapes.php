<?php
// A string KEY in an array literal escapes into the container: the array
// rc-retains it and outlives the literal, so the key must not be arena
// allocated. It was, and `arena_leave` reclaimed it on the way out of the
// function — every map built this way handed the caller keys that read as the
// LAST one written into the recycled buffer.

/** @return array<string,string> */
function rowOf(int $i): array { return ["k" . $i => "v" . $i, "z" . $i => "w" . $i]; }

$keys = [];
$vals = [];
for ($i = 0; $i < 4; $i++) {
    $r = rowOf($i);
    $keys[] = (string)array_key_first($r);
    $keys[] = (string)array_key_last($r);
    foreach ($r as $k => $v) { $vals[] = $k . "=" . $v; }
}
echo implode(",", $keys), "\n";
echo implode(",", $vals), "\n";

// The same shape read straight out of the returned temp.
$direct = [];
for ($i = 0; $i < 3; $i++) { $direct[] = (string)array_key_first(rowOf($i)); }
echo implode(",", $direct), "\n";

// A nested literal: the inner map's keys escape through the outer one.
/** @return array<string,array<string,int>> */
function nest(int $i): array { return ["outer" . $i => ["inner" . $i => $i]]; }
$n = [];
for ($i = 0; $i < 3; $i++) {
    $x = nest($i);
    foreach ($x as $ok => $inner) {
        foreach ($inner as $ik => $iv) { $n[] = $ok . "/" . $ik . "/" . $iv; }
    }
}
echo implode(",", $n), "\n";
