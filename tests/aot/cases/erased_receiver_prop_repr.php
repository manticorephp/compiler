<?php

// Reading a property through an ERASED (`mixed`) receiver must yield the same
// value a typed receiver does. Two slot shapes did not:
//   - a bare `array` hint erases its element to UNKNOWN, and an unknown was
//     passed through as if already boxed, so the raw buffer pointer var_dumped
//     as float(2.1490356046E-314);
//   - an enum slot holds an ORDINAL, handed back raw for a typed consumer, so
//     `$e->s === Suit::Spades` compared an ordinal against a cell and read false.

enum Suit
{
    case Hearts;
    case Spades;
}

class Typed
{
    public array $list = [];
    public Suit $s = Suit::Hearts;
    public int $i = 0;
    public string $str = '';
    public float $f = 0.0;
    public bool $b = false;
    public ?Typed $next = null;
}

class Other
{
    public array $list = [1];
    public Suit $s = Suit::Hearts;
}

function ident(mixed $v): mixed
{
    return $v;
}

$t = new Typed();
$t->list = [1, 'two', [3]];
$t->s = Suit::Spades;
$t->i = 7;
$t->str = 'txt';
$t->f = 2.5;
$t->b = true;
$t->next = new Typed();

echo "typed receiver:\n";
var_dump($t->list, $t->s === Suit::Spades, $t->i, $t->str, $t->f, $t->b);

echo "erased receiver:\n";
$e = ident($t);
var_dump($e->list, $e->s === Suit::Spades, $e->i, $e->str, $e->f, $e->b);
var_dump($e->s->name);
var_dump($e->next->i);
var_dump(count($e->list));

// More than one holder of the same property name forces the class_id switch.
$o = ident(new Other());
var_dump($o->list, $o->s === Suit::Hearts);
