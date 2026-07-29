<?php

// An enum case exports as a bare `\Enum::Case` — no array literal. It is still
// a nested VALUE, so inside a container it takes the same line break and indent
// every other object gets.

enum Suit: string
{
    case Hearts = 'H';
    case Spades = 'S';
}

enum Plain
{
    case One;
    case Two;
}

class Hand
{
    public Suit $suit = Suit::Hearts;
    public Plain $tag = Plain::One;
    public int $n = 3;
}

var_export(Suit::Hearts);
echo "\n";

var_export(Plain::Two);
echo "\n";

var_export([Suit::Hearts, Suit::Spades]);
echo "\n";

var_export(new Hand());
echo "\n";

var_export(['deck' => ['top' => Suit::Spades]]);
echo "\n";
