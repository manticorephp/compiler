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

// An enum case is a SINGLETON, so a round trip must yield the very same value,
// not a copy — and reading ->name / ->value off the `mixed` result must work.
$s = unserialize(serialize(Suit::Spades));
var_dump($s === Suit::Spades, $s->name);

$c = unserialize(serialize(Code::Bee));
var_dump($c === Code::Bee, $c->name, $c->value);

$l = unserialize(serialize(Level::High));
var_dump($l === Level::High, $l->value);

$arr = unserialize(serialize([Suit::Hearts, Suit::Hearts, Suit::Spades]));
var_dump($arr[0] === $arr[1], $arr[0] === Suit::Hearts, $arr[2] === Suit::Spades);
var_dump($arr);

class WithEnum
{
    public Suit $suit = Suit::Spades;
    public ?Code $code = null;
}

$w = new WithEnum();
$w->code = Code::A;
$w2 = unserialize(serialize($w));
var_dump($w2->suit === Suit::Spades, $w2->code === Code::A);
