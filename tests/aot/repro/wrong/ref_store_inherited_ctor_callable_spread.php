<?php
// A by-ref arg stored by an inherited constructor, first-class callable or spread call is passed as a stale copy.
// issue: #352
class B { public array $r = []; function __construct(mixed &$v = null) { if ($v !== null) $this->r[] = &$v; } function keep(mixed &$v) { $this->r[] = &$v; } }
class K extends B {}
$x = 1; $k = new K($x); $x = 2; echo $k->r[0], "\n";
$h = new B; $a = 1; $f = $h->keep(...); $f($a); $a = 2; echo $h->r[0], "\n";
$g = new B; $arr = [1]; $g->keep(...$arr); $arr[0] = 2; echo $g->r[0], "\n";
