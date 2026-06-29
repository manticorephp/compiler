<?php
class Counter {
    public int $value;
    public function __construct() {
        $this->value = 0;
    }
    public function inc(int $by): int {
        $this->value = $this->value + $by;
        return $this->value;
    }
    public function get(): int {
        return $this->value;
    }
}

$c = new Counter();
$c->inc(5);
$c->inc(10);
echo $c->get();
