<?php
// The union of two UNRELATED classes was `unknown`, so a heterogeneous object
// literal stored RAW pointers and stamped no repr bits — while the `mixed[]`
// parameter it was passed to said cells. The foreach then bound the value as a
// cell and `instanceof`, which requires the OBJECT tag, answered false for
// EVERY element. A cell is the honest top here: it carries the tag.
//
// The join also had to survive past two elements — `cell ∪ obj` collapsed back
// to unknown, so `[new A, new B]` was vec[cell] but `[new A, new B, new B]` was
// vec[unknown]. symfony's input definitions are 3-7 elements long.
class A { public function __construct(public string $n = 'a') {} public function tag(): string { return 'A:' . $this->n; } }
class B { public function __construct(public string $n = 'b') {} public function tag(): string { return 'B:' . $this->n; } }

class Sink
{
    /** @param mixed[] $items */
    public function __construct(array $items)
    {
        $o = '';
        foreach ($items as $it) { $o .= ($it instanceof B) ? 'B' : 'A'; }
        echo 'ctor: ', $o, "\n";
    }
}
class Taker
{
    /** @param mixed[] $items */
    public function take(array $items): void
    {
        $o = '';
        foreach ($items as $it) { $o .= ($it instanceof B) ? 'B' : 'A'; }
        echo 'method: ', $o, "\n";
    }
}

new Sink([new A(), new B(), new B(), new A()]);
(new Taker())->take([new A(), new B(), new B(), new A()]);

// Two elements (the join that already worked) must keep working.
$two = [new A('x'), new B('y')];
var_dump($two[1] instanceof B, $two[0] instanceof B);

// Longer literals keep every element self-describing.
$many = [new A('1'), new B('2'), new B('3'), new A('4'), new B('5')];
$out = [];
foreach ($many as $m) { $out[] = $m->tag(); }
echo \implode(',', $out), "\n";
foreach ($many as $m) { echo \get_class($m), ' '; }
echo "\n";

// A common base still wins over the cell floor.
class Base { public function who(): string { return 'base'; } }
class L extends Base { public function who(): string { return 'L'; } }
class R extends Base { public function who(): string { return 'R'; } }
$rel = [new L(), new R(), new L()];
foreach ($rel as $r) { echo $r->who(), ' '; }
echo "\n";
