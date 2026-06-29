<?php
// String building via repeated concatenation. Trip count seeded from $argc.
$acc = "";
$n = 4000000 * $argc;
for ($i = 0; $i < $n; $i++) {
    $acc = $acc . "x";
    if (\strlen($acc) > 64) { $acc = "x"; }
}
echo \strlen($acc), "\n";
