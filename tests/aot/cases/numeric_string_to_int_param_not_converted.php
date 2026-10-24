<?php
// A numeric string passed to an `int` parameter is not converted: the callee sees garbage instead of int(13)
function f(int $x) { return $x + 1; }
$p = explode(",", "12");
var_dump(f($p[0]));
class T { public int $n = 0; }
$t = new T();
$t->n = $p[0];
var_dump($t->n);
function r(): int { return "7"; }
var_dump(r());
