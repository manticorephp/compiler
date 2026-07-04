<?php
// Nullsafe `?->` property access renders NULL (not the value type's zero) when
// the receiver is null, across scalar / string / object / chained properties.
class Node {
    public ?Node $next = null;
    public int $v = 0;
    public string $label = "";
    public function __construct(int $v = 0, string $label = "") {
        $this->v = $v; $this->label = $label;
    }
}
$n = new Node(5, "head");
var_dump($n->next?->v);          // NULL
var_dump($n->next?->label);      // NULL
var_dump($n->next?->next?->v);   // NULL (chain)
echo "[", $n->next?->v, "]\n";   // [] — echo of null is ""
echo ($n->next?->v ?? -1), "\n"; // -1 via ??

$n->next = new Node(9, "tail");
var_dump($n->next?->v);          // int(9)
var_dump($n->next?->label);      // string(4) "tail"
echo $n->next?->v, " ", $n->next?->label, "\n";
echo ($n->next?->v ?? -1), "\n"; // 9

// nullsafe on an object property
class Wrap { public ?Node $inner = null; }
$w = new Wrap();
var_dump($w->inner?->v);
$w->inner = new Node(42);
var_dump($w->inner?->v);
