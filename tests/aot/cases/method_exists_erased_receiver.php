<?php
// method_exists() with a literal name over receivers whose class is known only at run time.

interface Shape { }
class Base implements Shape { public function area() { return 1; } }
class Square extends Base { public function SIDE() { return 2; } }
class Proxy { public function __call($n, $a) { return 'magic ' . $n; } }
enum Suit { case Hearts; public function color() { return 'red'; } }

function probe(mixed $v): string
{
    return (method_exists($v, 'area') ? 'A' : '-')
        . (method_exists($v, 'side') ? 'S' : '-')
        . (method_exists($v, '__call') ? 'M' : '-')
        . (method_exists($v, 'color') ? 'C' : '-');
}

function viaObject(object $o): string
{
    return (method_exists($o, 'Area') ? 'A' : '-') . (method_exists($o, 'nope') ? 'N' : '-');
}

$values = [new Base(), new Square(), new Proxy(), Suit::Hearts, fn () => 1, 'Square', 'square', 'Missing'];
foreach ($values as $v) {
    echo get_debug_type($v), ' ', probe($v), "\n";
}
echo viaObject(new Square()), viaObject(new Proxy()), "\n";

function callAny(mixed $o): string { return $o->area(); }
echo callAny(new Base()), ' ', callAny(new Proxy()), "\n";

// More classes than the inline compare chain: the switch helper.
class K0 { function hit() {} } class K1 { function hit() {} } class K2 { function hit() {} }
class K3 { function hit() {} } class K4 { function hit() {} } class K5 { function hit() {} }
class K6 { function hit() {} } class K7 { function hit() {} } class K8 { function hit() {} }
class K9 { function hit() {} } class KX { }
$n = 0;
foreach ([new K0(), new K3(), new K9(), new KX(), new Base()] as $k) {
    if (method_exists($k, 'hit')) { $n++; }
}
echo $n, "\n";
