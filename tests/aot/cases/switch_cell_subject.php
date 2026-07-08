<?php
// A switch on an untyped/`mixed` (NaN-boxed cell) subject must match with PHP's
// loose `==` juggling — a raw bit-compare of the boxed subject against a raw
// arm value never matches (boxed int 1 != raw 1) and would always hit default.
function f($n) {
    switch ($n) {
        case 1: case 2: return "low";
        case 3: return "mid";
        default: return "hi";
    }
}
echo f(1), f(2), f(3), f(9), "\n";

// Fall-through side effects (cell subject).
function g($n) {
    $r = "";
    switch ($n) {
        case 1: $r .= "a";
        case 2: $r .= "b"; break;
        case 3: $r .= "c"; break;
        default: $r .= "d";
    }
    return $r;
}
echo g(1), " ", g(2), " ", g(3), " ", g(9), "\n";

// Numeric-string juggling: cell subject, string arm ("5" == 5).
function h($n) {
    switch ($n) {
        case "5": return "five";
        case "x": return "ex";
        default: return "none";
    }
}
echo h(5), " ", h("x"), " ", h(7), "\n";
