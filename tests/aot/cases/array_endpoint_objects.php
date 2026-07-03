<?php
// array_first/array_last over object and enum arrays; cell-receiver property
// resolution (runtime class_id → slot) and enum ordinal dispatch.
class A { public int $v = 1; }
class B { public int $x = 9; public int $v = 99; }  // v at a different offset
class P { public function __construct(public string $name, public float $w) {} }

$as = [new A, new A];
$bs = [new B];
echo array_first($as)->v, " ", array_first($bs)->v, "\n";   // 1 99 (distinct offsets)

$ps = [new P("hydrogen", 1.0), new P("helium", 4.0)];
echo array_first($ps)->name, " ", array_last($ps)->w, "\n"; // hydrogen 4

// heterogeneous array: $a[0] is an object cell
$het = [new A, "x", 3];
echo $het[0]->v, "\n";                                       // 1

// method call on a cell receiver still works
class C { public int $n = 5; function dbl(): int { return $this->n * 2; } }
$cs = [new C];
echo array_first($cs)->dbl(), "\n";                          // 10

enum Suit: string { case Hearts = "H"; case Spades = "S"; }
enum Bare { case North; case South; }
enum Level: int { case Low = 1; case High = 10; }

$suits = [Suit::Hearts, Suit::Spades];
echo count($suits), " ", array_first($suits)->name, " ", array_last($suits)->value, "\n";
foreach ($suits as $s) { echo $s->value; }
echo "\n";
$levels = [Level::Low, Level::High];
echo array_first($levels)->value, " ", array_last($levels)->name, "\n";
$bares = [Bare::North, Bare::South];
echo $bares[0]->name, " ", $bares[1]->name, "\n";
