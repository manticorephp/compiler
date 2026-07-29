<?php

enum Suit
{
    case Hearts;
    case Spades;
}

enum Code: string
{
    case A = 'a';
    case Bee = 'bee';
}

enum Level: int
{
    case Low = 1;
    case High = 9;
}

echo serialize(Suit::Hearts), "\n";
echo serialize(Suit::Spades), "\n";
echo serialize(Code::A), "\n";
echo serialize(Code::Bee), "\n";
echo serialize(Level::High), "\n";

echo serialize([Suit::Hearts, Suit::Spades]), "\n";
// An enum case is a singleton, so a repeat is a back-reference like any object.
echo serialize([Suit::Hearts, Suit::Hearts]), "\n";
echo serialize(['s' => Code::A, 't' => Code::A, 'u' => Code::Bee]), "\n";

class WithEnum
{
    public Suit $suit = Suit::Spades;
    public ?Code $code = null;
}

$w = new WithEnum();
$w->code = Code::Bee;
echo serialize($w), "\n";
echo serialize([$w, $w]), "\n";
