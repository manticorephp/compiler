<?php
// strcat — 200 K-iteration string append (buffer growth + copy).
$s = "";
for ($i = 0; $i < 200000; $i++) {
    $s .= "x";
}
echo strlen($s), "\n";
