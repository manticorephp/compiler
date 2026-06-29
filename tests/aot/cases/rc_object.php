<?php
class Box {
    public int $v;
    public function __construct(int $v) { $this->v = $v; }
    public function get(): int { return $this->v; }
}
class Pair {
    public Box $a;
    public function __construct(Box $a) { $this->a = $a; }
    public function sum(): int { return $this->a->get(); }
}
function drop(int $i): int {
    $b = new Box($i);
    return $b->get();
}
function transfer(int $i): Box {
    $b = new Box($i);
    return $b;
}
function keep(int $i): Pair {
    $b = new Box($i);
    return new Pair($b);
}
echo drop(5); echo "\n";
$h = transfer(7);
echo $h->get(); echo "\n";
$p = keep(9);
echo $p->sum(); echo "\n";
$total = 0;
for ($k = 0; $k < 4; $k = $k + 1) { $total = $total + drop($k); }
echo $total; echo "\n";
