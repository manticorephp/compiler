<?php
namespace App;
use Manticore\Ds\Int16Array;
class A { public $x = 1; protected $y = 'p'; private $z = [1, ['k' => null]]; public ?A $next = null; }
final class B extends A { private int $own = 5; public float $f = 1.5; public bool $t = true; public bool $n = false; }
final class D { public function __debugInfo(): array { return ['shown' => 1, 2 => 'two']; } }
enum Suit: string { case Hearts = 'h'; case Spades = 's'; }
enum Pure { case One; }
$a = new A(); $a->next = new B();
print_r($a);
echo "\n";
print_r([new D(), 'e' => Suit::Spades, 'p' => Pure::One]);
$o = new \stdClass(); $o->a = 1; $o->list = [1, 2]; $o->child = new \stdClass();
print_r($o);
echo print_r(new B(), true);
print_r(Int16Array::fromArray([4, 5]));
echo "\n";
