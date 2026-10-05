<?php
// A backed enum case held in a mixed value: `$v instanceof Suit` then `$v->name` crashes
// issue: #98
enum Suit: string { case Hearts = "h"; case Spades = "s"; }
function f(mixed $v): string { if ($v instanceof Suit) { return "n=" . $v->name . " v=" . $v->value; } return ""; }
echo f(Suit::Spades), "\n";
