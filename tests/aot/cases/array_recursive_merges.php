<?php

// array_replace_recursive + array_merge_recursive. Both recurse through values
// that reach them as CELLS, so each level rebuilds its two sides into
// literal-bound locals with a `/** @var array<array-key, mixed> */` binding —
// a cell argument gives Monomorphize an empty callKey, and without a definite
// repr the erased body would run for every nested level.
//
// array_merge_recursive was held back until an argument carrying BOTH int and
// string keys stopped mangling its collisions. That was never in the function:
// a mixed-key LITERAL typed its key by unioning the key kinds, and `string ∪
// int` collapses to UNKNOWN — neither an assoc nor a cell-keyed array — so
// foreach fell back to the raw i64 key channel and a string key was read as its
// pointer. See the mixed_key_literal case for the compiler-level regression.

$base = ['a' => 1, 'nested' => ['x' => 1, 'y' => 2], 'list' => [1, 2]];
$over = ['b' => 2, 'nested' => ['y' => 20, 'z' => 30], 'list' => [9]];
print_r(array_replace_recursive($base, $over));

// Depth 4, and a sibling key that only one side has.
print_r(array_replace_recursive(
    ['x' => ['y' => ['z' => ['w' => 1, 'keep' => 'me']]]],
    ['x' => ['y' => ['z' => ['v' => 2]]]],
));

// The config-merge shape this exists for.
$cfg = ['db' => ['host' => 'localhost', 'port' => 3306, 'opts' => ['charset' => 'utf8']]];
$env = ['db' => ['port' => 5432, 'opts' => ['timeout' => 5]], 'debug' => true];
print_r(array_replace_recursive($cfg, $env));

// Replace over lists reindexes nothing: index 0 is replaced, 1 survives.
print_r(array_replace_recursive([1, 2, 3], [9], [null, 8]));

// Single argument, empty operands.
print_r(array_replace_recursive(['keep' => 'me']));
print_r(array_replace_recursive([], []));

// --- array_merge_recursive ---

// The repro that held it back: BOTH int and string keys in one argument.
print_r(array_merge_recursive(
    ['color' => ['favorite' => 'red'], 5],
    [10, 'color' => ['favorite' => 'blue']],
));

// php's own documentation example.
print_r(array_merge_recursive(
    ['color' => ['favorite' => 'red'], 5],
    [10, 'color' => ['favorite' => 'green', 'blue']],
));

// A string-key collision merges: two scalars become a LIST of both, and a
// scalar meeting an array joins it (php's `(array)` promotion), both ways.
print_r(array_merge_recursive(['a' => 1], ['a' => 2]));
print_r(array_merge_recursive(['a' => 'x'], ['a' => ['y', 'z']]));
print_r(array_merge_recursive(['a' => ['y', 'z']], ['a' => 'x']));

// Depth 3 with a sibling only one side has.
print_r(array_merge_recursive(
    ['x' => ['y' => ['z' => 1, 'keep' => 'me']]],
    ['x' => ['y' => ['w' => 2]]],
));

// Int keys RENUMBER (unlike array_replace_recursive), at every level.
print_r(array_merge_recursive([1, 2], [3], ['k' => 'v'], [4]));
print_r(array_merge_recursive(['a' => [1, 2]], ['a' => [3]]));

// Single argument, empty operands.
print_r(array_merge_recursive(['keep' => 'me', 7]));
print_r(array_merge_recursive([], []));
