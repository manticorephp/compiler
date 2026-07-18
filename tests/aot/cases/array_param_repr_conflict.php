<?php
// A body heuristic concretizes a bare-`array` param to ONE element repr (the
// concat '.' guesses vec[string]), but a call site passes a concrete array with
// a DIFFERENT repr (vec[int], raw int slots). The single body would read a raw
// int as a string pointer -> SIGSEGV. Monomorphize must recognise the concrete
// param / concrete arg CONFLICT and clone one $mono$ body per repr.

function tag(array $a): string { return 'got:' . $a[0]; }

// Two sites, conflicting reprs: string vs int (two distinct keys).
var_dump(tag(['grr']));
var_dump(tag([7]));

// Both sites agree on int while the body guessed string (a single key — the
// clone must still fire, minKeys=1).
function label(array $a): string { return 'v=' . $a[0]; }
var_dump(label([1]));
var_dump(label([2]));

// A single conflicting site (guess string, arg int).
function once(array $a): string { return 'x' . $a[0]; }
var_dump(once([42]));

// A float repr differs from the string guess too (double bits vs ptr).
function show(array $a): string { return '#' . $a[0]; }
var_dump(show(['a']));
var_dump(show([3.5]));

// Concrete-vs-CELL: a heterogeneous vec[cell] site cannot be specialized per
// repr, so the param floors to vec[cell] and the concat unboxes each element.
// The string site clones off the cell param; the cell site reads cells directly.
function pick(array $a): string { return '>' . $a[0]; }
var_dump(pick(['solo']));       // vec[string]
var_dump(pick(['mix', 9]));     // vec[cell] — elem 0 is a boxed string
var_dump(pick([11, 'z']));      // vec[cell] — elem 0 is a boxed int
