<?php
// array_shift / array_pop of an emptied int list answer int(0) instead of NULL
// issue: #312
$a = [1];
array_shift($a);
var_dump(array_shift($a));
$b = [2];
array_pop($b);
var_dump(array_pop($b));
