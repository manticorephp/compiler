<?php
// `f($this->$name)` into an `array &$x` parameter segfaults
// issue: #201
final class B
{
    public array $a = [1 => [1]];

    public function g(string $n): void { self::f($this->$n); }

    public static function f(array &$x): void { $x[9] = [6]; }
}
$o = new B();
$o->g('a');
echo json_encode($o->a), "\n";
