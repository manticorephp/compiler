<?php

// An enum case travels as an ORDINAL, not an object pointer. The cell-array
// rebuild used to `box_object` the raw word, so an enum case inside an array
// reached a tagged consumer as tag-8-payload-0: var_dump printed NULL for it
// and then dereferenced null (SIGSEGV). An indexed read was fine, which is
// what hid it — only the whole-array walk went through the rebuild.

enum Suit
{
    case Hearts;
    case Spades;
}

enum Code: string
{
    case A = 'a';
    case B = 'b';
}

$a = [Suit::Hearts, Suit::Spades];
var_dump($a[0]);
var_dump($a);

$m = ['first' => Suit::Spades, 'second' => Suit::Hearts];
var_dump($m);

$b = [Code::A, Code::B];
var_dump($b);
foreach ($b as $c) { echo $c->name, '=', $c->value, "\n"; }

var_dump(array_values($a));

$nested = [[Suit::Hearts], [Suit::Spades, Suit::Hearts]];
var_dump($nested);
