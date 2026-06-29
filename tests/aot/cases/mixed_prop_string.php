<?php
function dyn(): string { return "dyn"; }
class C { public mixed $x = null; }
$c = new C();
var_dump($c->x);
$c->x = "hello";
var_dump($c->x);
echo $c->x, "\n";
echo strlen($c->x), "\n";
$c->x = dyn() . "!";
var_dump($c->x);
$c->x = 7;
var_dump($c->x);
$c->x = "back";
var_dump($c->x);
