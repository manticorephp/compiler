<?php
// A `mixed` property that holds an array-of-arrays must box as a cell-array, so
// a nested value read back preserves its array-ness. Was a raw untagged pointer
// → is_array()/var_dump() saw a garbage float. An array-of-SCALARS slot (a key
// buffer read as a raw index, e.g. SPL's iterator) must stay raw — covered by
// spl_array_classes; here we only assert the nested case flips.
class Cfg { public mixed $data; }

$c = new Cfg();
$c->data = ['a' => [1, 2], 'b' => [3, 4]];
var_dump(is_array($c->data['a']));
echo count($c->data['a']), "\n";
var_dump($c->data['b']);
$x = $c->data['a'];
var_dump(is_array($x));
echo $x[0] + $x[1], "\n";
foreach ($c->data as $k => $row) { echo $k, ":", implode(",", $row), " "; }
echo "\n";

// vec-of-vecs
class Grid { public mixed $rows; }
$g = new Grid();
$g->rows = [[1, 2, 3], [4, 5, 6]];
var_dump(is_array($g->rows[1]));
echo $g->rows[1][2], "\n";
