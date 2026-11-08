<?php
namespace Rules;
class Enum { public function __construct(public string $t = 'x') {} public static function make(): static { return new static('made'); } }
class Sub extends Enum {}
enum Suit: string { case H = 'h'; }
echo (new Enum)->t, "\n", Enum::make()->t, "\n", Sub::make()->t, "\n", Suit::H->value, "\n";
Enum::make();
