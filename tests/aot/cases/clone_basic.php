<?php
// clone: independent shallow copy; rc-managed props are co-owned (shared);
// __clone() runs on the copy; PHP 8.5 clone-with overrides land last.
class Inner { public int $v = 1; }

class Box {
    public int $n = 0;
    public string $tag = "box";
    public Inner $in;
    public function __construct() { $this->in = new Inner(); }
    public function __clone(): void { $this->n = $this->n + 1000; }
}

$a = new Box();
$a->n = 5;
$a->tag = "first";
$a->in->v = 7;

$b = clone $a;
$b->tag = "second";           // scalar copy is independent
$b->in->v = 99;               // shared Inner — affects both

echo "a: ", $a->n, " ", $a->tag, " ", $a->in->v, "\n";
echo "b: ", $b->n, " ", $b->tag, " ", $b->in->v, "\n";

$c = clone($a, ["n" => 42, "tag" => "withed"]);
echo "c: ", $c->n, " ", $c->tag, "\n";
echo "a-after: ", $a->n, " ", $a->tag, "\n";
