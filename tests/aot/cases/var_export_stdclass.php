<?php

// stdClass exports as `(object) array(…)` and closes with ONE paren, not two.
// A #[AllowDynamicProperties] class keeps its own name and prints the declared
// slots first, then the bag.

$o = new stdClass();
$o->k = 1;
$o->j = 's';
$o->nested = ['a' => 1];
var_export($o);
echo "\n";

$empty = new stdClass();
var_export($empty);
echo "\n";

// Nested inside an array, and inside another stdClass.
var_export([$o]);
echo "\n";

$outer = new stdClass();
$outer->inner = $empty;
$outer->n = null;
var_export($outer);
echo "\n";

#[\AllowDynamicProperties]
class Loose
{
    public int $declared = 7;
}

$l = new Loose();
$l->extra = 'dyn';
var_export($l);
echo "\n";
