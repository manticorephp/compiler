<?php
class C { public mixed $x = null; public mixed $y = 5; }
$c = new C();
var_dump($c->x);
var_dump($c->y);
$c->x = 3.5;
var_dump($c->x);
$c->x = 42;
var_dump($c->x);
$c->x = true;
var_dump($c->x);
$c->x = null;
var_dump($c->x);
var_dump($c->x === null);
