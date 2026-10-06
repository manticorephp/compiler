<?php
// array_walk callback storing a string through &$v over an int array that is a function parameter reads back int(0)
// issue: #68
// item: 13
function w(array $x) {
    array_walk($x, function (&$v) { $v = "z"; });
    var_dump($x);
}
w([1, 2]);
