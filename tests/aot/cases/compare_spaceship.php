<?php
// `<=>` is a MIR primitive: each operand is evaluated exactly ONCE. The old
// `($a > $b) - ($a < $b)` expansion ran both twice — side effects included.
function bump(int &$n, int $v): int { $n = $n + 1; return $v; }
$calls = 0;
$r = bump($calls, 3) <=> bump($calls, 5);
var_dump($r, $calls);

$eq = 0;
var_dump(bump($eq, 7) <=> bump($eq, 7), $eq);

// Values: always exactly -1 / 0 / +1, never a raw byte difference.
var_dump(1 <=> 2, 2 <=> 1, 2 <=> 2);
var_dump(1.5 <=> 2.5, 2.5 <=> 1.5, 1.5 <=> 1.5);
var_dump(1 <=> 2.5, 2.5 <=> 1, 2.0 <=> 2);
var_dump("a" <=> "b", "b" <=> "a", "a" <=> "a");
var_dump("abc" <=> "abd", "abd" <=> "abc");

// Two numeric strings order NUMERICALLY.
var_dump("10" <=> "9", "10" <=> "10.0", "1e2" <=> "100");

// A number against a NON-numeric string: PHP casts the number to a string, so
// '5' < 'a'. A raw carrier compare only agreed by accident.
var_dump(5 <=> "abc", "abc" <=> 5, 0 <=> "abc", "abc" <=> 0);
var_dump("abc" <=> 99999999999, 99999999999 <=> "abc");
var_dump("0abc" <=> 5, 5 <=> "0abc");

// Arrays: fewer members is smaller, else element-wise.
var_dump([1,2] <=> [1,3], [1,2,3] <=> [1,2], [1,2] <=> [1,2]);
var_dump([[1,2]] <=> [[1,3]]);

// null / bool go through the bool row.
var_dump(null <=> false, true <=> false, false <=> true, null <=> 0);

// Through erased (cell) operands and as a sort comparator.
function o(mixed $v): mixed { return $v; }
var_dump(o(2) <=> o(1), o("a") <=> o("b"), o(1.5) <=> o(1.5));
$s = [3, 1, 2];
usort($s, fn($a, $b) => $a <=> $b);
print_r($s);
$w = ["img12", "img10", "img2"];
usort($w, fn($a, $b) => $a <=> $b);
print_r($w);
