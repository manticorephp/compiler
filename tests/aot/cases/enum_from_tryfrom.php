<?php
enum Suit: string {
    case Hearts = 'H';
    case Diamonds = 'D';
    case Clubs = 'C';
    case Spades = 'S';
}
enum Level: int {
    case Low = 0;
    case Mid = 5;
    case High = 10;
}

// from() — hit
$s = Suit::from('D');
echo $s->name, " ", $s->value, "\n";
$l = Level::from(10);
echo $l->name, " ", $l->value, "\n";

// from() — miss throws catchable ValueError with PHP's exact message
try { Suit::from('Z'); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
try { Level::from(99); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }

// tryFrom() — hit returns the case, miss returns null
$t = Suit::tryFrom('C');
echo $t->name, "\n";
var_dump(Suit::tryFrom('X'));
var_dump(Suit::tryFrom('S'));

// ordinal-0 case via tryFrom (the null-vs-ordinal-0 guard)
$z = Level::tryFrom(0);
echo $z->name, " ", $z->value, "\n";
var_dump(Level::tryFrom(0));
var_dump(Level::tryFrom(7));

// tryFrom in a null check, then use the case
$m = Suit::tryFrom('H');
if ($m !== null) {
    echo "have ", $m->name, " (", $m->value, ")\n";
}
$none = Suit::tryFrom('?');
echo $none === null ? "absent\n" : "present\n";
