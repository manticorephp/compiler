<?php
// strops — strtoupper / str_replace loop (PHP-level string stdlib).
$base = "the quick brown fox jumps over the lazy dog";
$acc = 0;
for ($i = 0; $i < 200000; $i++) {
    $u = strtoupper($base);
    $r = str_replace("O", "0", $u);
    $acc += strlen($r);
}
echo $acc, "\n";
