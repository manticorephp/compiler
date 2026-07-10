<?php
$a = ['x' => 1, 'y' => 2];
$b = ['y' => 3, 'z' => 4];
var_dump([...$a, ...$b]);

$base = ['a' => 1, 'b' => 2, 10, 20];
$over = ['b' => 99, 'c' => 3, 30];
var_dump([...$base, ...$over]);

var_dump(['pre' => 0, ...$base, 'post' => 7]);

$s = ['one', 'two'];
var_dump([...$s, ...$s]);

$e = [];
var_dump([...$e, 'x' => 1]);
var_dump([...[], 5, 'k' => 9]);

$nums = [...[1, 2], ...[3, 4]];
var_dump($nums);

$assoc = ['p' => 5, 'q' => 6];
var_dump([...$assoc]);
