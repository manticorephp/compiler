<?php
// A dynamic numeric-string array key stays a string: $h[$s] with $s="5" is not stored as int 5
$s = '5';
$h = [];
$h[$s] = 1;
var_dump(array_keys($h));
var_dump($h[5] ?? 'miss');
var_dump(isset($h[5]));
