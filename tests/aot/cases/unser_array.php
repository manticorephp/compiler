<?php

var_dump(unserialize('a:0:{}'));
var_dump(unserialize('a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}'));
var_dump(unserialize('a:2:{s:1:"x";i:1;s:1:"y";s:3:"str";}'));
var_dump(unserialize('a:3:{i:5;s:4:"five";s:1:"k";s:1:"v";i:6;s:3:"six";}'));
var_dump(unserialize('a:1:{s:5:"outer";a:1:{s:5:"inner";a:1:{i:0;b:1;}}}'));
var_dump(unserialize('a:5:{i:0;b:1;i:1;b:0;i:2;N;i:3;d:1.5;i:4;s:3:"str";}'));
var_dump(unserialize('a:1:{i:-3;s:3:"neg";}'));

// Round trips.
$cases = [
    [],
    [1, 2, 3],
    ['a' => 1, 'b' => [2, 3]],
    [true, false, null, 1.5, 'x', -0.0],
    ['nested' => ['deep' => ['deeper' => 'v']]],
    [5 => 'five', 'k' => 'v', 6 => 'six'],
];
foreach ($cases as $c) {
    $s = serialize($c);
    echo $s, "\n";
    var_dump(unserialize($s) == $c);
}
