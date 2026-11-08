<?php
$f = 'array_first'; $l = 'array_last';
var_dump($f([3, 4, 5]), $l([3, 4, 5]), $f([]), $l([]), $f(['a' => 'x', 'b' => 'y']), $l(['a' => 'x', 'b' => 'y']));
var_dump(array_first([7, 8]), array_last([7, 8]), call_user_func('array_last', [1, 2, 9]));
$g = array_first(...); var_dump($g(['q']));
