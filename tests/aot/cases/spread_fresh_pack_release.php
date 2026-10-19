<?php
// A fresh spread pack (literal or call result) is released right after the call, for every call kind.
class D { function __destruct() { echo "D "; } }
class O {
    function __construct(mixed $a = 0, mixed $b = 0) {}
    function m(mixed $a, mixed $b): string { return is_string($a) ? $a : "y"; }
    static function s(mixed $a, mixed $b): string { return "s"; }
}
function u(mixed $a, mixed $b): string { return is_string($a) ? $a : "y"; }
function h(mixed ...$a): int { return count($a); }
function g(mixed $a): array { return [$a, new D]; }
function t(array $p, mixed $m, mixed $mf): void {
    $c = $p[0]; $o = new O; $mn = "m";
    $f = function (mixed $a, mixed $b) { return "f"; };
    $r = u(...g($c)); echo $r, "\n";
    $r = u(...[$c, new D]); echo $r, "\n";
    $r = $o->m(...g($c)); echo $r, "\n";
    $r = O::s(...g($c)); echo $r, "\n";
    $r = new O(...g($c)); echo "new\n";
    $r = $o->$mn(...g($c)); echo $r, "\n";
    $r = $mf(...g($c)); echo $r, "\n";
    $r = $f(...g($c)); echo $r, "\n";
    $r = h(...g($c)); echo $r, "\n";
    $r = sprintf("%s!", ...g($c)); echo $r, "\n";
    $r = sprintf("%s!", ...$m); echo $r, "\n";
    printf("%s!", ...[$c, new D]); echo "\n";
    $q = $p; array_unshift($q, ...g($c)); echo count($q), "\n"; $q = [];
    echo "end\n";
}
t(["ab", 1], ["cd", 2], "u");
