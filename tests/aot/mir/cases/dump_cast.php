<?php
function casts(mixed $x): string {
    $i = (int) $x;
    $f = (float) $x;
    $s = (string) $i;
    $b = (bool) $x;
    return $s . ($b ? "1" : "0");
}
echo casts("42");
