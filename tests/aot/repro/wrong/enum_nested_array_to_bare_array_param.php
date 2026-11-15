<?php
// A nested array of enum cases passed to a bare `array` parameter reads back as garbage.
// issue: #701
enum Suit { case Hearts; case Spades; }
function deep(array $x) { return $x['k'][1]->name; }
$loc = [Suit::Hearts, Suit::Spades];
$f = function (int $n) use ($loc) { $s = ''; for ($i = 0; $i < $n; $i++) { $s .= deep(['k' => [Suit::Hearts, Suit::Spades]]) . deep(['k' => $loc]); } return $s; };
echo $f(2), "\n";
