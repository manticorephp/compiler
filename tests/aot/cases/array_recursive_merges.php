<?php

// array_replace_recursive (array_merge_recursive is still held back — see the
// epic memory: an array carrying BOTH int and string keys mangles its
// collisions). It recurses through values that reach it as CELLS, so each level
// rebuilds its two sides into
// literal-bound locals with a `/** @var array<array-key, mixed> */` binding —
// a cell argument gives Monomorphize an empty callKey, and without a definite
// repr the erased body would run for every nested level.

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
