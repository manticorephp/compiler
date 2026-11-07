<?php
// isset($o->$name) and $o->$name on an erased receiver ignore visibility: a private property reads as set from outside its class.
// issue: #587
class Pg { private int $n = 1; protected int $q = 2; public int $pub = 5; }
function g(mixed $o, mixed $i) { return isset($o->$i) ? 'set' : 'no'; }
echo g(new Pg, 'pub'), g(new Pg, 'n'), g(new Pg, 'q'), "\n";
function r(mixed $o, mixed $i) { try { return $o->$i; } catch (Error $e) { return $e->getMessage(); } }
echo r(new Pg, 'pub'), ' ', r(new Pg, 'n'), ' ', r(new Pg, 'q'), "\n";
