<?php
// Passing an object property by reference, reference-assigning a property, and
// returning a property by reference — all route through container-slot
// addressing (a GEP to the field).
class Point { public int $x = 1; public int $y = 2; public string $label = "p"; }
class Line { public Point $a; function __construct() { $this->a = new Point(); } }

function bump(int &$v) { $v = $v + 10; }
function grow(string &$s) { $s = $s . "!"; }

// by-ref argument
$p = new Point();
bump($p->x);
bump($p->y);
grow($p->label);
echo $p->x, " ", $p->y, " ", $p->label, "\n";   // 11 12 p!

// nested property by-ref argument
$l = new Line();
bump($l->a->x);
echo $l->a->x, "\n";                              // 11

// reference-assign to a property, mutate through the alias
$q = new Point();
$rx = &$q->x;
$rx = 5;
$rx++;
echo $q->x, "\n";                                 // 6
$rl = &$q->label;
$rl = "hello";
echo $q->label, "\n";                             // hello

// return a property by reference from a free function, bind and mutate
function &getX(Point $o) { return $o->x; }
$pt = new Point();
$ref = &getX($pt);
$ref = 42;
$ref++;
echo $pt->x, "\n";                                // 43
