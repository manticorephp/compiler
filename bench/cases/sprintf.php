<?php
// sprintf — formatting loop (int/float/string conversion specifiers).
$acc = 0;
for ($i = 0; $i < 200000; $i++) {
    $s = sprintf("%05d-%.2f-%s", $i, $i * 1.5, "row");
    $acc += strlen($s);
}
echo $acc, "\n";
