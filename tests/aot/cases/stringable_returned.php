<?php
class Money {
    public function __construct(public int $a, public string $c) {}
    public function __toString(): string { return $this->a . " " . $this->c; }
}
function get(): Money { return new Money(7, "USD"); }
echo get();
