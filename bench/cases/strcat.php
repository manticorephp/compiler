<?php
// strcat — 200 K-iteration string append (buffer growth + copy). Runtime bound
// (* $argc) so strlen can't be folded without running the appends.
$n = 30000000 * $argc;
$s = "";
for ($i = 0; $i < $n; $i++) {
    $s .= "x";
}
echo strlen($s), "\n";
