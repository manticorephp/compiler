<?php
// `(object)$array` publishes the array as the object's property BAG, and the bag
// is a CELL channel: every reader unboxes what it finds. A concrete-element
// array holds RAW words, so the values have to be boxed at the cast — otherwise
// each reader decodes the int as the double with those bits.
echo json_encode((object)['k' => 2]), "\n";
echo json_encode((object)['k' => 2, 'j' => 3]), "\n";
echo json_encode((object)['k' => "s"]), "\n";
echo json_encode((object)['k' => 2.5]), "\n";
echo json_encode((object)['k' => true]), "\n";
echo json_encode((object)['k' => null]), "\n";

$arr = ['a' => 1, 'b' => 2];
$o = (object)$arr;
var_dump($o->a, $o->b);
var_dump(get_object_vars($o));
var_dump((array)$o);
// The source array must survive the cast unchanged.
var_dump($arr);

$mixed = ['n' => 1, 's' => "x", 'f' => 0.5, 'b' => false, 'z' => null];
echo json_encode((object)$mixed), "\n";
// ⛔ NOT covered here: `(object)[10, 20]` — php makes a stdClass with the
// numeric-STRING properties "0" and "1" (`{"0":10,"1":20}`), this build keeps
// the vec shape and answers `[10,20]`. A separate gap: the cast would have to
// rebuild a vec as a string-keyed assoc, which is not what this case is about.
