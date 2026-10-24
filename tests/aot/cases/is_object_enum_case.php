<?php
// is_object() answers false for an enum case (php: true).
enum Suit: string { case H = 'h'; case S = 's'; }
var_dump(is_object(Suit::H));
var_dump(is_object(Suit::S));
var_dump(is_null(Suit::H));
function f(mixed $x) { var_dump(is_object($x)); }
f(Suit::H);
function g(Suit $x) { var_dump(is_object($x), is_null($x)); }
g(Suit::H);
function h(?Suit $x) { var_dump(is_object($x), is_null($x)); }
h(null);
h(Suit::H);
var_dump(is_object(Suit::from('h')), is_object(Suit::tryFrom('zz')));
