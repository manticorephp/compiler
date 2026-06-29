<?php
// Element-as-key: a string foreach VALUE used as an assoc KEY. The bare-array
// param is typed vec[string] by call-site inference, so `$o[$v]` must build a
// string-keyed assoc, not a positional vec.
function flip(array $a): array {
    $o = [];
    foreach ($a as $v) {
        $o[$v] = 1;
    }
    return $o;
}

function count_vals(array $a): array {
    $o = [];
    foreach ($a as $v) {
        if (isset($o[$v])) {
            $o[$v] = $o[$v] + 1;
        } else {
            $o[$v] = 1;
        }
    }
    return $o;
}

$f = flip(["x", "y", "z"]);
foreach ($f as $k => $v) {
    echo $k, "=", $v, "\n";
}

$c = count_vals(["a", "b", "a", "c", "b", "a"]);
foreach ($c as $k => $v) {
    echo $k, ":", $v, "\n";
}
