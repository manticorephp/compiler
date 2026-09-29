<?php
// (string) of an object without __toString throws php's Error (the address was
// printed); a subclass reached through a base type still answers its own.
class Plain {}
class Base {}
class Named extends Base { public function __toString(): string { return "named"; } }

function viaMixed(mixed $v): string { return (string)$v; }
function viaObject(object $v): string { return (string)$v; }
function viaBase(Base $v): string { return "<" . $v . ">"; }

$cases = [
    fn() => (string)new Plain(),
    fn() => "x" . new Plain(),
    fn() => viaMixed(new Plain()),
    fn() => viaObject(new Plain()),
    fn() => viaObject(new Named()),
    fn() => viaBase(new Named()),
    fn() => viaBase(new Base()),
    fn() => viaMixed(new DateTime('2020-01-01')),
    fn() => viaMixed(fn() => 1),
];
foreach ($cases as $i => $c) {
    try {
        echo $i, ": ", $c(), "\n";
    } catch (Error $e) {
        echo $i, ": ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
$p = new Plain();
try { echo $p, "\n"; } catch (Error $e) { echo "echo: ", $e->getMessage(), "\n"; }
try { echo "in $p\n"; } catch (Error $e) { echo "interp: ", $e->getMessage(), "\n"; }
