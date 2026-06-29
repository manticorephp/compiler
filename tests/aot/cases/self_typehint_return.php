<?php
class Box {
    public function __construct(public int $n) {}
    public function bump(): self { return new self($this->n + 1); }
}
$a = new Box(5);
$b = $a->bump();
echo $b->n;
