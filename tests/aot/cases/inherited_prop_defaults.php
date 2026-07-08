<?php
// A subclass instance must initialise inherited property defaults too — PHP
// applies EVERY declared default at construction, not just the leaf class's.
class A { public string $k = 'a'; public int $x = 1; public int $y = 2; }
class B extends A { public int $z = 3; }                       // synthesized ctor
class C extends B { public int $w = 4; function __construct() { $this->x = 99; } } // explicit ctor, no parent::
class D extends A { public string $k = 'd'; }                  // redeclaration shadows parent default
class E { public int $x = 1; function __construct() { $this->x = 50; } }
class F extends E {}                                           // inherits E's ctor
class G extends E { function __construct() { parent::__construct(); $this->x = $this->x + 1; } }

$b = new B(); echo "$b->k $b->x $b->y $b->z\n";
$c = new C(); echo "$c->k $c->x $c->y $c->z $c->w\n";
$d = new D(); echo "$d->k $d->x\n";
$f = new F(); echo "$f->x\n";
$g = new G(); echo "$g->x\n";
