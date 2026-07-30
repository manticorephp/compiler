<?php
// The subclass is DECLARED FIRST; it must still get the parent's slots.
class Child extends Parent_
{
    public function describe(): string { return $this->tag() . ':' . $this->name . '/' . $this->n; }
}

class Parent_
{
    public string $name = 'p';
    public int $n = 0;
    protected string $extra = 'e';
    public function __construct(string $name = 'p', int $n = 7) { $this->name = $name; $this->n = $n; }
    public function tag(): string { return 'base'; }
}

class GrandChild extends Child
{
    public string $own = 'g';
    public function tag(): string { return 'grand'; }
}

$c = new Child('kid', 3);
echo $c->describe(), "\n";
$g = new GrandChild('gk', 9);
echo $g->describe(), ' own=', $g->own, "\n";
$p = new Parent_();
echo $p->name, ' ', $p->n, ' ', $p->tag(), "\n";
$g->name = 'renamed';
echo $g->describe(), ' own=', $g->own, "\n";
