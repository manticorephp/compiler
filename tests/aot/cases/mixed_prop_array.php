<?php
// A heterogeneous `mixed` property (a "bag" that holds scalars/strings AND
// arrays) self-describes: an array store boxes as a cell-array so a whole-value
// var_dump / json_encode / count dispatches by tag instead of mis-reading a raw
// array pointer. (A pure array-subscripted backing slot stays raw — see SPL.)
class Bag { public mixed $v = null; }

$b = new Bag();
var_dump($b->v);
$b->v = 42;
var_dump($b->v);
$b->v = "hi";
var_dump($b->v);
$b->v = [1, 2, ["x" => 3]];
var_dump($b->v);
echo json_encode($b->v), "\n";
echo count($b->v), "\n";
$b->v = true;
var_dump($b->v);
$b->v = ["a" => 1, "b" => 2];
var_dump($b->v);
$b->v = null;
var_dump($b->v);
var_dump($b->v === null);
