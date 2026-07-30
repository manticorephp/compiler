<?php
// A dispatch site coerces its arguments ONCE, against the fallback's signature.
// Candidates reached through an ERASED receiver are matched by method NAME, so
// they can be unrelated classes whose parameter REPRS differ — a raw `string`
// in one, a `string|int` CELL in another. The arm that disagreed read the
// other's representation.
//
// symfony: a closure captured `$definition` (captures are cells), so
// `$definition->getArgument($x)` enumerated every class with a `getArgument`
// method — six `Input::getArgument(string)` arms and one
// `InputDefinition::getArgument(string|int)`. The single cell_to_strptr the
// call site emitted handed the InputDefinition arm a raw pointer in a cell.
class Def { public function get(string|int $name): string { return 'D:' . $name; } }
class Inp { public function get(string $name): string { return 'I:' . $name; } }
class Num { public function get(int $name): string { return 'N:' . (string) $name; } }

function callIt($obj, $key): string { return $obj->get($key); }

$d = new Def();
$i = new Inp();
$n = new Num();
echo callIt($d, 'alpha'), "\n";
echo callIt($i, 'alpha'), "\n";
echo callIt($n, 7), "\n";

// Receiver erased into a closure capture — the symfony shape exactly.
$run = static function ($o) { return $o->get('beta'); };
echo $run($d), "\n";
echo $run($i), "\n";

// And the same through an array of mixed receivers.
$all = [$d, $i];
foreach ($all as $o) { echo $o->get('gamma'), ' '; }
echo "\n";
