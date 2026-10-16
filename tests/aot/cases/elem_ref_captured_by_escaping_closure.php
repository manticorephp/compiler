<?php
// `$r = &$a[$k]` captured by reference in a closure that outlives the array reads the element, not freed memory.
function one(array $t): \Closure {
    $r = &$t[0];
    return static function () use (&$r) { return $r; };
}
function each_(array $t): array {
    $out = [];
    foreach ($t as $k => $u) {
        $l = &$t[$k];
        $out[] = static function () use (&$l) { $l = is_array($l) ? $l[0] : strtoupper($l); return $l; };
    }
    return $out;
}
var_dump(one([['x'], 'str'])());
foreach (each_([['y'], 'str', ['z']]) as $f) { var_dump($f(), $f()); }
