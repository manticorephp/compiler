<?php
// Appending under an absent inner key of an erased parent prints "" for the inner array
// issue: #51
$m = ['a' => 1];
$m['x']['y'][] = 1.5;
var_dump($m);
