<?php
function g(?int $n): string {
    if ($n === null) return "none";
    return "n=" . $n;
}
echo g(5), "\n";
echo g(0), "\n";
echo g(null), "\n";
var_dump(g(null) === "none");
function add(?int $n): int { return $n + 10; }   // null -> 0 -> 10
var_dump(add(5));
var_dump(add(null));
function fav(?float $f): string {
    if ($f === null) return "?";
    return "f=" . $f;
}
echo fav(2.5), "\n";
echo fav(null), "\n";
var_dump(fav(null));
class C { public ?int $count = null; }
$c = new C();
var_dump($c->count);
$c->count = 0;
var_dump($c->count);
$c->count = 7;
var_dump($c->count);
