<?php
// A heterogeneous array assigned WHOLE to a bare-`array` property types vec[cell]
// / assoc[K,cell] on the DECLARED property, so a later `$this->p[$i]` / foreach
// dispatches on the runtime tag (a LOCAL het literal already works). Later
// typed element appends into that cell slot box correctly too.
class V {
    public array $v;
    public array $m;
    function __construct() { $this->v = [1, "x"]; $this->m = ["a" => 1, "b" => "y"]; }
    function addF(float $f): void { $this->v[] = $f; }
    function addI(int $i): void { $this->v[] = $i; }
    function put(string $k, int $n): void { $this->m[$k] = $n; }
    function dump(): void {
        foreach ($this->v as $e) { var_dump($e); }
        foreach ($this->m as $k => $x) { echo "$k="; var_dump($x); }
    }
}
$o = new V();
$o->addF(3.5);
$o->addI(9);
$o->put("c", 3);
$o->dump();
