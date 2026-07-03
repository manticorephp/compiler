<?php
// PHP 8.4 asymmetric visibility — parsed (write-scope not enforced).
class Counter {
    public private(set) int $count = 0;
    protected(set) string $label = "start";
    public function tick(): void { $this->count++; }
    public function rename(string $s): void { $this->label = $s; }
}
$c = new Counter();
echo $c->count, " ", $c->label, "\n";
$c->tick(); $c->tick();
$c->rename("go");
echo $c->count, " ", $c->label, "\n";

class Point {
    public function __construct(
        public private(set) int $x,
        public protected(set) int $y = 0,
    ) {}
    public function moveX(int $d): void { $this->x += $d; }
}
$p = new Point(3, 4);
echo $p->x, ",", $p->y, "\n";
$p->moveX(10);
echo $p->x, ",", $p->y, "\n";
