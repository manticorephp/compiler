<?php

class A { public int $x = 0; public string $s = "init"; }
class B { public int $x = 0; public float $f = 1.5; }

// $o erased to a classless receiver — dynamic-name store must dispatch on the
// runtime class_id to the real slot, not blind-write offset 16.
function put(object $o, string $p, $v): void { $o->$p = $v; }

$a = new A();
put($a, 'x', 7);
put($a, 's', "hello");
var_dump($a->x);
var_dump($a->s);

$b = new B();
put($b, 'x', 42);
put($b, 'f', 3.25);
var_dump($b->x);
var_dump($b->f);

// static-name store on a classless (mixed) receiver.
function putx(mixed $o, int $v): void { $o->x = $v; }
$a2 = new A();
putx($a2, 99);
var_dump($a2->x);

// dynamic name that lands on the other class's slot layout.
put($a, 'x', 5);
put($b, 'x', 6);
var_dump($a->x);
var_dump($b->x);
