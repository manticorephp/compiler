<?php
// A cell-typed `static` alternating an OBJECT (borrowed from a property) and an
// int: the cell keeps the object alive past its owner, exactly as php does —
// the destructor fires at the OVERWRITE, not at the owner's death. Both store
// shapes: a concrete value per branch (the box-back arm) and one ternary cell.
class Obj {
    public int $v;
    public function __construct(int $v) { $this->v = $v; }
    public function __destruct() { echo "bye ", $this->v, "\n"; }
}
class Box {
    public Obj $obj;
    public function __construct(int $v) { $this->obj = new Obj($v); }
    public function step(int $i): void {
        static $c;
        if ($c !== null) {
            echo "prev: ", $c instanceof Obj ? "obj" . $c->v : "int" . $c, "\n";
        }
        if ($i % 2 === 0) {
            $c = $this->obj;
        } else {
            $c = 7;
        }
    }
    public function tern(int $i): void {
        static $c;
        if ($c !== null) {
            echo "tprev: ", $c instanceof Obj ? "obj" . $c->v : "int" . $c, "\n";
        }
        $c = ($i % 2 === 0) ? $this->obj : 7;
    }
}
for ($i = 0; $i < 4; $i++) {
    $b = new Box($i);
    $b->step($i);
    unset($b);
    echo "after ", $i, "\n";
}
for ($i = 10; $i < 14; $i++) {
    $b = new Box($i);
    $b->tern($i);
    unset($b);
    echo "after ", $i, "\n";
}
echo "done\n";
