<?php
// A denied read of a private int property that falls back to __get returning a string yields garbage instead of the string
// issue: #419
class A {
    private int $x = 1;
    public function __get($n) { return "magic:$n"; }
}
$o = new A;
var_dump($o->x);
