<?php

// A CELL element must survive a later CONCRETE store. Two ways it was lost:
//
// (Storing such a RETURNED array into another cell-element array is a separate,
// still-open tail — see the epic memory.)
//
//  * the running element was read off `isAssoc()`, which is string-key-only, so
//    a CELL-KEYED assoc (any local whose key comes from an erased foreach) threw
//    its element away on every store and the LAST store's value type became the
//    whole element;
//  * `unionTypes` has no notion of cell being the TOP of the element lattice, so
//    it happily returned the concrete side.
//
// Either way the function's RETURN type went with it, and a caller then rebuilt
// the boxed array as if its values were raw (SIGSEGV) or read them raw.

function build(array $a): array {
    /** @var array<string, mixed> $out */
    $out = [];
    foreach ($a as $k => $v) { $out[$k] = $v; }   // erased values → cells
    $out['extra'] = [1, 2];                       // a CONCRETE store, last
    return $out;
}

print_r(build(['c' => 1]));

// The same shape without the docblock: the element is inferred, and a nested
// array value still round-trips through a `mixed` consumer.
function mixedBag(): array {
    /** @var array<string, mixed> $b */
    $b = [];
    $b['n'] = 1;
    $b['s'] = 'two';
    $b['a'] = ['x' => 3];
    $b['f'] = 4.5;
    $b['z'] = null;
    return $b;
}
print_r(mixedBag());
var_dump(is_array(mixedBag()['a']), mixedBag()['n'], mixedBag()['s']);

// A cell-keyed local (int AND string keys) keeps its element across stores.
function mixedKeys(array $src): array {
    /** @var array<array-key, mixed> $o */
    $o = [];
    foreach ($src as $k => $v) { $o[$k] = $v; }
    $o[7] = 'seven';
    $o['tail'] = [8, 9];
    return $o;
}
print_r(mixedKeys(['a' => 1, 2 => 'b']));
