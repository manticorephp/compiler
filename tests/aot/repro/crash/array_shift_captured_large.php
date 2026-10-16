<?php
// array_shift on a large array captured by value in a closure frees a pointer malloc never returned at exit.
// issue: #275
$l = range(0, 2500);
$f = function () use ($l) {
    array_shift($l);
    return count($l);
};
echo $f(), "\n";
