<?php
// JSON and string helpers.
$d = ['name' => 'Ann', 'tags' => ['x', 'y'], 'n' => 3];
$j = json_encode($d);
$back = json_decode($j, true);
echo $j, ' ', str_pad((string)$back['n'], 4, '0', STR_PAD_LEFT), ' ', ucfirst(strtolower('HELLO')), "\n";
