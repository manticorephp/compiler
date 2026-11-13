<?php
class A { var $e = false; var $f = 1, $g; }
class B extends A {
    /** @var A */
    public $e;
}
$b = new B();
var_dump($b->e, $b->f, $b->g);
$r = new ReflectionProperty(A::class, "f");
var_dump($r->isPublic());
