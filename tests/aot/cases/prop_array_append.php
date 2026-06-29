<?php
class Buf {
    public array $items;
    public function __construct() { $this->items = []; }
    public function push(int $v): void { $this->items[] = $v; }
}
$b = new Buf();
$b->push(10); $b->push(20); $b->push(30);
echo count($b->items), ":", $b->items[0], ",", $b->items[1], ",", $b->items[2];
