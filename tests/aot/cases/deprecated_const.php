<?php

// #[\Deprecated] on a class constant, a global constant and an enum case.

class Holder
{
    #[\Deprecated("gone")]
    const OLD = 3;

    const KEPT = 4;
}

enum Suit: string
{
    #[\Deprecated("old suit")]
    case Hearts = 'H';

    case Spades = 'S';
}

#[\Deprecated("konst", since: "3.1")]
const TOP_OLD = 7;

const TOP_KEPT = 8;

echo "start\n";
echo Holder::OLD, "\n";
echo Holder::KEPT, "\n";
$s = Suit::Hearts;
echo $s->value, "\n";
$t = Suit::Spades;
echo $t->value, "\n";
echo TOP_OLD, "\n";
echo TOP_KEPT, "\n";
echo "end\n";
