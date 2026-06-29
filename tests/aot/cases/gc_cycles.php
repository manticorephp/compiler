<?php
class GcNode {
    public ?GcNode $ref = null;
    public int $v = 0;
    public string $tag = "";
    public array $items = [];
}
function strCycle(): void {
    $a = new GcNode(); $b = new GcNode();
    $a->tag = "alpha-payload-payload";
    $b->tag = "beta-payload-payload";
    $a->ref = $b; $b->ref = $a;
}
function selfCycle(): void {
    $a = new GcNode();
    $a->ref = $a;
}
function twoCycle(): void {
    $a = new GcNode(); $b = new GcNode();
    $a->ref = $b; $b->ref = $a;
}
function threeCycle(): void {
    $a = new GcNode(); $b = new GcNode(); $c = new GcNode();
    $a->ref = $b; $b->ref = $c; $c->ref = $a;
}
function mkCycle(): GcNode {
    $a = new GcNode(); $b = new GcNode();
    $a->ref = $b; $b->ref = $a;
    return $a;
}

selfCycle();
echo "self=" . gc_collect_cycles() . "\n";
twoCycle();
echo "two=" . gc_collect_cycles() . "\n";
threeCycle();
echo "three=" . gc_collect_cycles() . "\n";
strCycle();
echo "str=" . gc_collect_cycles() . "\n";
echo "empty=" . gc_collect_cycles() . "\n";

$x = mkCycle();
echo "live=" . gc_collect_cycles() . "\n";
$x = mkCycle();
echo "reassigned=" . gc_collect_cycles() . "\n";
