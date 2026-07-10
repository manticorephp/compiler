<?php
// A bare-`array` property with an EMPTY `[]` default that receives element
// stores (appends / keyed) of DIFFERENT concrete types is a mixed slot →
// vec[cell] / assoc[K,cell], each element boxed so a read dispatches on the tag.
// Exercises int+float+string (the empty-[] cell buffer must handle a float box)
// and a heterogeneous assoc. Regression guard for the null-init obj-local
// self-host bug in inferPropElemFromStores (int+float raced numeric widening).
class Bag {
    public array $v = [];
    public array $m = [];
    function ai(int $x): void { $this->v[] = $x; }
    function af(float $x): void { $this->v[] = $x; }
    function as_(string $x): void { $this->v[] = $x; }
    function pi(string $k, int $x): void { $this->m[$k] = $x; }
    function ps(string $k, string $x): void { $this->m[$k] = $x; }
    function dump(): void {
        foreach ($this->v as $e) { var_dump($e); }
        foreach ($this->m as $k => $x) { echo "$k="; var_dump($x); }
    }
}
$b = new Bag();
$b->ai(7);
$b->af(3.5);
$b->as_("hi");
$b->pi("a", 1);
$b->ps("b", "x");
$b->dump();
