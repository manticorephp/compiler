<?php
// A nested append through an erased middle level writes the fresh inner array
// back raw; the parent must be described so var_dump reads an array, not an int.
$o = ['k' => []];
$o['k']['z'][] = 1.5;
$o['k']['z'][] = 2.5;
var_dump($o);
$p = ['k' => []];
$p['k']['w'][] = 'a';
$p['k']['w'][] = 'b';
$p['k']['n'][] = 7;
var_dump($p);
