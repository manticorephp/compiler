<?php
// `$obj::class` on an operand whose static type is erased (object, mixed, object|string) or polymorphic.
namespace App;
interface Shape {}
abstract class Base implements Shape {
    public function self(): string { return $this::class; }
    public static function late(): string { return static::class; }
}
class Circle extends Base {}
final class Square extends Base {}

function viaUnion(object|string $o): string { if (\is_object($o)) { $o = $o::class; } return $o; }
function viaObject(object $o): string { return $o::class; }
function viaMixed(mixed $o): string { return \is_object($o) ? $o::class : 'none'; }
function viaBase(Base $o): string { return $o::class; }
function viaInterface(Shape $o): string { return $o::class; }

foreach ([new Circle(), new Square()] as $o) {
    echo viaUnion($o), ' ', viaObject($o), ' ', viaMixed($o), ' ', viaBase($o), ' ', viaInterface($o), ' ', $o->self(), "\n";
}
echo viaUnion(Circle::class), ' ', viaMixed(5), "\n";
echo Circle::late(), ' ', Square::late(), "\n";
$c = new Circle();
echo $c::class, ' ', (new Square())::class, "\n";
