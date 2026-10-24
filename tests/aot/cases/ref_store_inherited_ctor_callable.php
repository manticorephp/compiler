<?php
class B { public array $r = []; function __construct(mixed &$v = null) { if ($v !== null) $this->r[] = &$v; } function keep(mixed &$v) { $this->r[] = &$v; } }
class K extends B {}
$x = 1; $k = new K($x); $x = 2; echo $k->r[0], "\n";
$none = null; $h = new B($none); $a = 1; $f = $h->keep(...); $f($a); $a = 2; echo $h->r[0], "\n";
