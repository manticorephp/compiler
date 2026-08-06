<?php

// An UNTYPED property read through an ERASED receiver.
//
// `emitFixedPropLoad`'s contract is a TAGGED CELL, but a property with no
// declared type had its slot handed back RAW — so the reader took the bare word
// for a NaN-boxed one and int 5 read as float(2.5E-323), a string as a denormal.
// The typed-receiver read of the same property was always correct, which is what
// kept this invisible: only an erased carrier (`: mixed` return, a mixed
// property, an array element) reaches the class_id-dispatched path.

class Untyped { public $id = 5; public $name = 'n'; public $f = 1.5; public $b = true; }
class Typed { public int $id = 7; }          // a second holder forces the class_id switch

function erase(): mixed { return new Untyped(); }

$e = erase();
var_dump($e->id, $e->name, $e->f, $e->b);
var_dump(is_int($e->id), is_string($e->name), is_float($e->f), is_bool($e->b));
echo $e->id, "|", $e->name, "|", $e->f, "|", ($e->b ? 'y' : 'n'), "\n";

// the typed-receiver path must keep working
$t = new Untyped();
var_dump($t->id, $t->name);

// a write through the erased receiver, read back through both
$e->id = 42;
$e->name = 'zz';
var_dump($e->id, $e->name);

// the other holder still dispatches to its own slot
function erase2(): mixed { return new Typed(); }
var_dump(erase2()->id);
