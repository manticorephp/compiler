<?php
// A bare-`array` property fed by appends of an element sourced from a typed
// call-site array must NOT erase to unknown (which rendered each element as a
// garbage float): method call-site inference types the param, then
// property-element-from-stores types the property.
class C {
    public array $lines = [];
    public array $nums = [];
    function addLines(array $ms): void { foreach ($ms as $m) { $this->lines[] = $m; } }
    function addNums(array $ns): void { foreach ($ns as $n) { $this->nums[] = $n; } }
    function show(): void {
        echo implode(",", $this->lines), "\n";
        $t = 0; foreach ($this->nums as $n) { $t = $t + $n; }
        echo $t, "\n";
    }
}
$c = new C();
$c->addLines(["a", "b", "c"]);
$c->addNums([10, 20, 30]);
$c->show();

class P { public function __construct(public int $id) {} }
class Reg {
    public array $items = [];
    function add(array $ps): void { foreach ($ps as $p) { $this->items[] = $p; } }
    function ids(): void { foreach ($this->items as $it) { echo $it->id, ","; } echo "\n"; }
}
$r = new Reg();
$r->add([new P(1), new P(2), new P(3)]);
$r->ids();
