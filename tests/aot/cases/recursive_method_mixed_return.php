<?php
// A function returning the result of a method that recurses and returns a string at the base prints an address instead of the string.
function via(int $n) {
    return (new K)->m($n);
}
class K {
    function m(int $n) {
        if ($n === 0) { return "bt"; }
        return via($n - 1);
    }
}
echo via(3), "\n";
