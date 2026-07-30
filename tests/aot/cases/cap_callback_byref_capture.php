<?php
// @epic: element-repr
// @why: `$out = []; $collector->collect($out);` is THE PHP accumulator idiom --
//       symfony's EventDispatcher, config merging and every DI compiler pass use
//       it. Values written through the reference come back as raw bits.
//
// The discrimination matters more than the failure: only an array that is EMPTY
// at the call site is affected. Seed it with one element and the same callee
// writes correctly, so this is the element-repr hint going unset on an empty
// literal, not a by-reference bug.

function capAppend(array &$x): void { $x[] = 'str'; }

$empty = [];
capAppend($empty);
var_dump($empty);          // <- corrupt: no element to infer a hint from

$seeded = ['pre'];
capAppend($seeded);
var_dump($seeded);         // <- correct

$plain = [];
$plain[] = 'str';
var_dump($plain);          // <- correct: no reference involved

$viaClosure = [];
$f = function () use (&$viaClosure) { $viaClosure[] = 'str'; $viaClosure['k'] = 7; };
$f();
var_dump($viaClosure);     // <- same corruption through a closure capture

function capReadBack(array &$x): string { $x[] = 's'; return $x[0]; }
$r = [];
var_dump(capReadBack($r)); // <- correct INSIDE the callee; only the caller reads bits
var_dump($r);
