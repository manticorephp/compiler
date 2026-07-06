<?php
// An enum case boxed into a heterogeneous cell array must round-trip its class
// identity: === compares by case, var_dump renders enum(Enum::Case), and a
// direct enum var_dump works too (all boxed the per-case singleton object).
enum Suit: int { case H = 1; case S = 2; case C = 3; }

$e = Suit::S;
var_dump($e);                       // direct enum
var_dump($e === Suit::S);
var_dump($e === Suit::H);

$bag = [Suit::S, "x", 42, Suit::H, 2.5];   // heterogeneous -> vec[cell]
var_dump($bag[0]);                  // enum from a cell
echo ($bag[0] === Suit::S) ? "hit\n" : "miss\n";
echo ($bag[3] === Suit::S) ? "hit\n" : "miss\n";

$n = 0;
foreach ($bag as $x) { if ($x === Suit::S) { $n++; } }
echo $n, "\n";

$m = ["k" => Suit::C, "s" => "str"];       // heterogeneous assoc
echo ($m["k"] === Suit::C) ? "assoc-hit\n" : "assoc-miss\n";
var_dump($m["k"]);
