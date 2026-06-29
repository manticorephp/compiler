<?php
// multiple returns of distinct value kinds -> cell return type
function multi(int $n) {
    if ($n > 10) return "big";
    if ($n > 0) return 1.5;
    return $n;
}
var_dump(multi(20));
var_dump(multi(5));
var_dump(multi(-3));
echo multi(20), " ", multi(5), " ", multi(-3), "\n";

// int|float multi-return: var_dump keeps the distinct tags
function f(bool $b) {
    if ($b) return 10;
    return 2.5;
}
var_dump(f(true));
var_dump(f(false));

// used in a string/concat context (tagged_to_str)
function tag(int $n) {
    if ($n > 0) return "pos";
    return $n;
}
echo "r=" . tag(5) . "/" . tag(-2) . "\n";
