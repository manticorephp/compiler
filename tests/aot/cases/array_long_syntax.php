<?php
$x = array(1, 2, 'k' => 3);
echo $x[0], $x[1], $x['k'], "\n";
$y = array('a' => array(10, 20), 'b' => [30]);
echo $y['a'][1], $y['b'][0], "\n";
$e = array();
var_dump(count($e));
$mix = array('x', 5 => 'y', 'z');
echo $mix[0], $mix[5], $mix[6], "\n";
