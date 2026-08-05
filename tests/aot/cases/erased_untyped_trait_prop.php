<?php

// The same untyped-property root, reached through a TRAIT.
//
// A class's own properties and its mixed-in trait properties are lowered by two
// separate walks, so the `untyped IS mixed` rule has to hold in both: the trait
// walk kept calling lowerTypeHint(null) -> UNKNOWN, and a mixed-in `public $id = 5`
// read back through an erased receiver as float(2.5E-323), exactly as a declared
// one did before the fix.

trait Bag { public $id = 5; public $name = 'n'; public $f = 1.5; }

class Holder { use Bag; }
class Other { public int $k = 7; }          // a second holder forces the class_id switch

function erase(): mixed { return new Holder(); }

$e = erase();
var_dump($e->id, $e->name, $e->f);
var_dump(get_object_vars($e));

// a write through the erased receiver, read back through it
$e->id = 42;
var_dump($e->id);

// the typed-receiver path must keep working
$h = new Holder();
var_dump($h->id, $h->name);

// the other holder still dispatches to its own slot
function erase2(): mixed { return new Other(); }
var_dump(erase2()->k);
