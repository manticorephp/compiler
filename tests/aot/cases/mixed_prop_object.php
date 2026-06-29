<?php
class A {
    public int $n = 5;
    public string $tag = "aye";
    public function hi(): string { return "hi-A"; }
}
class Box { public mixed $v = null; }
$b = new Box();
$b->v = new A();
var_dump($b->v instanceof A);
var_dump($b->v instanceof Box);
if ($b->v instanceof A) {
    echo $b->v->n, " ", $b->v->tag, " ", $b->v->hi(), "\n";
}
$b->v = 42;
var_dump($b->v);
$b->v = "str";
var_dump($b->v);
