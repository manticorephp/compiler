<?php
// strops — strtoupper / str_replace. The subject is picked data-dependently so
// the constant transform isn't hoisted.
$bases = ["the quick brown fox", "jumps over the lazy dog", "pack my box with jugs"];
$m = count($bases);
$acc = 1;
for ($i = 0; $i < 300000; $i++) {
    $u = strtoupper($bases[($i + $acc) % $m]);
    $r = str_replace("O", "0", $u);
    $acc += strlen($r) + ($i & 7);
}
echo $acc, "\n";
