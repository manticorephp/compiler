<?php
// array_shift on a large array captured by value in a closure frees a pointer malloc never returned at exit.
$l = range(0, 2500);
$f = function () use ($l) {
    array_shift($l);
    return count($l);
};
echo $f(), "\n";
$g = function () use ($l) { $l[] = 1; array_pop($l); array_pop($l); return count($l); };
echo $g(), ' ', $g(), ' ', count($l), "\n";
