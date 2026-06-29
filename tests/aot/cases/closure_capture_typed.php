<?php
$arr = ["x", "y", "z"];
$f = function() use ($arr) { echo $arr[0], $arr[1], $arr[2], "\n"; };
$f();

$s = "hello";
$g = function() use ($s) { echo strtoupper($s), " ", strlen($s), "\n"; };
$g();

$nums = [10, 20, 30];
$sum = function() use ($nums) {
    $t = 0;
    foreach ($nums as $n) { $t = $t + $n; }
    return $t;
};
echo $sum(), "\n";

class Box { public function __construct(public string $label) {} }
$box = new Box("tag");
$show = function() use ($box) { echo $box->label, "\n"; };
$show();
