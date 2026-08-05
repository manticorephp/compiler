<?php

// `get_object_vars()` / `(array)` on a receiver whose STATIC type names no class.
//
// emitDeclaredPropsArray keys off the static class name, so an erased receiver
// walked NO properties and the call answered an EMPTY array — while `var_dump`
// of the very same value was correct, because it dispatches on the RUNTIME class
// (__mir_dump_object's instanceof chain). The fix is the class_id switch
// emitCellPropertyRead already uses, with the dynamic bag as the default arm.

class Plain { public int $n = 1; public string $s = 'a'; }
class Untyped { public $n = 2; public $s = 'b'; }
class Derived extends Plain { public float $f = 2.5; }

function erase(mixed $v): mixed { return $v; }

var_dump(get_object_vars(erase(new Plain())));
var_dump(get_object_vars(erase(new Untyped())));
var_dump(get_object_vars(erase(new Derived())));

// the statically-typed path must keep working
$p = new Plain();
var_dump(get_object_vars($p));

// a stdClass reaches the DEFAULT arm — its properties are all dynamic
$o = new stdClass();
$o->x = 1;
$o->y = 'two';
var_dump(get_object_vars(erase($o)));

// reading the bag twice must not disturb it (the arm co-owns, never steals)
$vars = get_object_vars(erase($o));
$again = get_object_vars(erase($o));
var_dump($vars, $again, $o->x, $o->y);
