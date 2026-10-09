<?php
// A Closure or an enum case as a Map key / Set member: identity semantics.
use Manticore\Ds\{Map, Set};

enum Suit { case Hearts; case Spades; }
enum Rank: int { case Ace = 1; case King = 13; }

$f = fn() => 1;
$g = fn() => 1;
$m = new Map();
$m->set($f, 'f');
$m->set($g, 'g');
$m->set(Suit::Hearts, 'hearts');
$m->set(Rank::Ace, 'ace');
$m->set(Suit::Hearts, 'hearts2');
echo count($m), "\n";
var_dump($m->get($f), $m->get($g), $m->get(Suit::Hearts), $m->get(Rank::Ace));
var_dump($m->has(Suit::Spades), $m->has(Rank::King), $m->has(fn() => 1));
var_dump(isset($m[$f]), $m[Suit::Hearts]);
$m->remove($f);
var_dump($m->has($f), $m->has($g), count($m));
foreach ($m as $k => $v) { echo get_debug_type($k), ' => ', $v, "\n"; }

$s = new Set();
$s->add($f);
$s->add($f);
$s->add(Suit::Spades);
$s->add(Suit::Spades);
$s->add(Rank::King);
echo count($s), "\n";
var_dump($s->has($f), $s->has($g), $s->has(Suit::Spades), $s->has(Suit::Hearts), $s->has(Rank::King));
$s->remove(Suit::Spades);
var_dump($s->has(Suit::Spades), count($s));
