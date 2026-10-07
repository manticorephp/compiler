<?php
// int list built in a nested literal, widened by a float append, prints the float bits as int
// issue: #74
// item: 22
$s = ['k' => ['z' => [1]]];
$s['k']['z'][] = 1.5;
var_dump($s);
