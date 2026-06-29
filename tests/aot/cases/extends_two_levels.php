<?php
class A {
    public int $x;
    public function __construct(int $x) { $this->x = $x; }
    public function val(): int { return $this->x; }
}
class B extends A {
    public int $y;
    public function __construct(int $x, int $y) {
        $this->x = $x;
        $this->y = $y;
    }
    public function sum(): int { return $this->x + $this->y; }
}
class C extends B {
    public int $z;
    public function __construct(int $x, int $y, int $z) {
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
    }
    public function total(): int { return $this->sum() + $this->z; }
}
$c = new C(1, 2, 3);
echo $c->val(), ",", $c->sum(), ",", $c->total();
