<?php
final class Inner {
    public function __construct(public readonly int $value) {}
    public function bump(): int { return $this->value + 1; }
}
final class Outer {
    public function __construct(public readonly Inner $inner) {}
}
$o = new Outer(new Inner(40));
echo $o->inner->bump();
