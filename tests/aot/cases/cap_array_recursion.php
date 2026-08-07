<?php
// @epic: stdlib-arrays
// @why: symfony/config merges bundle configuration trees with the recursive
//       array helpers, and Console's Application does the same for definitions.
//       analyze reported several of these as undefined against the green corpus.

$a = ['x' => ['p' => 1, 'q' => [1, 2]], 'y' => 'keep'];
$b = ['x' => ['q' => [9], 'r' => 3]];

var_dump(array_replace_recursive($a, $b));
var_dump(array_merge_recursive($a, $b));

$flat = [];
array_walk_recursive($a, function ($v, $k) use (&$flat) { $flat[] = "$k=$v"; });
var_dump($flat);

var_dump(array_diff_ukey(['a' => 1, 'b' => 2], ['A' => 1], 'strcasecmp'));
var_dump(array_intersect_ukey(['a' => 1, 'b' => 2], ['A' => 1], 'strcasecmp'));
