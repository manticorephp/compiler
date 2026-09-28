<?php
// Property defaults are applied once, when the object is allocated — not by the
// constructor: parent::__construct() after the child assigned a parent property
// keeps it, and a second __construct() call does not reset anything.
trait T { public string $t = 'trait'; }
class P
{
    public int $x = 1;
    public array $l = [];
    public function __construct() { $this->l[] = 'p'; }
}
class C extends P
{
    use T;
    public string $own = 'own';
    public function __construct() { $this->x = 5; $this->t = 'set'; $this->l[] = 'c'; parent::__construct(); }
}
class Leaf extends C {}
class Count
{
    public int $n = 0;
    public int $h = 7 { set(int $v) => $v * 100; }
    public function __construct(int $k = 1) { $this->n++; echo "ctor $k\n"; }
}
$c = new C();
var_dump($c->x, $c->t, $c->own, $c->l);
$l = new Leaf();
var_dump($l->x, $l->t, $l->l);
$o = new Count(5);
$o->__construct(7);
var_dump($o->n, $o->h);
$r = (new ReflectionClass(C::class))->newInstanceWithoutConstructor();
var_dump($r->x, $r->t, $r->own, $r->l);
$u = unserialize('O:1:"P":1:{s:1:"l";a:0:{}}');
var_dump($u->x);
