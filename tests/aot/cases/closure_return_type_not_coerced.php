<?php
// A closure with a declared scalar return type does not coerce its return value.
$g = fn($c, $v): string => $c + $v;
var_dump($g(1, 2));
$h = function ($x): int { return $x . '5'; };
var_dump($h(1));
