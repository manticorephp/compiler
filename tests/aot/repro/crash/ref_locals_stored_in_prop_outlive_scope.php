<?php
// Two function locals stored by reference in an array property outlive their function: reads and writes through them corrupt the heap.
// issue: #341
class H {
    /** @var array<int,mixed> */
    public array $refs = [];
    function bind(int $k, mixed &$v): void { $this->refs[$k] = &$v; }
}
class O { public string $n = "obj"; }
function mk(): H { $h = new H; $h->bind(1, $a); $h->bind(2, $b); return $h; }
$o = new O;
$h = mk();
$h->refs[1] = "v1";
$h->refs[2] = "v2";
$o2 = new O;
echo $o->n, "\n";
echo $h->refs[1], $h->refs[2], "\n";
