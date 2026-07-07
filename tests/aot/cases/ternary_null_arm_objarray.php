<?php
// obj|null and array|null ternary arms. An array arm lifts to a cell (null
// renders NULL, not array(0); is_null/gettype dispatch on the runtime tag). An
// object arm keeps the obj type (null = 0 ptr) so clone/method still bind to the
// static class; is_null/is_object/gettype runtime-check the pointer instead of
// short-circuiting on the static obj type.

class Node {
    public int $v;
    public ?Node $next = null;
    public function __construct(int $v) { $this->v = $v; }
    public function m(): int { return $this->v; }
    public function chain(): Node { return $this; }
}

function useObj(bool $c): void {
    $o = $c ? new Node(9) : null;
    var_dump(is_null($o), is_object($o), gettype($o), get_debug_type($o));
    var_dump($o === null, $o instanceof Node);
    var_dump($o?->v);
    if ($o !== null) {
        var_dump($o->m());
        $cl = clone $o;                 // clone must keep the static class
        $cl->v = 100;
        var_dump($o->v, $cl->v);        // COW independence
        var_dump($o->chain()->m());     // chained call
        $o->next = new Node(1);         // property write
        var_dump($o->next->v);
    }
    $o2 = $o ?? new Node(7);
    var_dump($o2->v);
}
useObj(false);
useObj(true);

function useArr(bool $c): void {
    $a = $c ? [10, 20, 30] : null;
    var_dump(is_null($a), gettype($a), $a === null);
    var_dump($a ?? "none");
    if ($a !== null) {
        var_dump(count($a));
        foreach ($a as $x) { echo $x, " "; }
        echo "\n";
        var_dump($a[1]);
        $a[] = 40;
        var_dump($a);
    }
}
useArr(false);
useArr(true);

// object-null flowing through a nullable return + into an array
function pick(bool $c): ?Node { return $c ? new Node(5) : null; }
$arr = [pick(false), pick(true)];
foreach ($arr as $x) { var_dump($x === null ? "n" : $x->v); }

// nested ternary, both obj arms null
$b = false;
$z = $b ? new Node(1) : ($b ? new Node(2) : null);
var_dump(is_null($z));

// ternary-cell passed to a typed nullable param
function takes(?Node $n): string { return $n === null ? "null" : "node:" . $n->v; }
var_dump(takes(true ? new Node(3) : null));
var_dump(takes(false ? new Node(3) : null));

// instanceof through an interface on an obj-null arm (null-guarded class-id load)
interface Shape { public function area(): float; }
class Circle implements Shape {
    public function __construct(public float $r) {}
    public function area(): float { return 3.14 * $this->r * $this->r; }
}
function mkShape(bool $c): ?Shape { return $c ? new Circle(2.0) : null; }
foreach ([mkShape(false), mkShape(true)] as $s) {
    var_dump($s instanceof Shape, $s instanceof Circle);
    var_dump($s === null ? 0.0 : $s->area());
}

// closure null arm: is_null runtime-checks the pointer
$f = (1 > 2) ? fn() => 1 : null;
var_dump(is_null($f));
$g = (1 < 2) ? fn() => 1 : null;
var_dump(is_null($g));
