<?php
function rs1(): int { return 7.0; }
function rs2(): float { return "7.5"; }
function rs3(): string { return 7; }
function rs4(): int { $s = explode(",", "8"); return $s[0]; }
var_dump(rs1(), rs2(), rs3(), rs4());
function f(float $x) { return $x; }
class T2 { public int $n = 0; public float $f = 0.0; public string $s = ""; }
$p = explode(",", "12");
$t = new T2();
var_dump(f($p[0]), f(3));
$t->f = $p[0]; $t->n = 5.0; $t->s = 9; $t->n = "33";
var_dump($t->f, $t->n, $t->s);
class T3 { public int $n = 0; } $t = new T3(); $t->n = 5.0; var_dump($t->n);
