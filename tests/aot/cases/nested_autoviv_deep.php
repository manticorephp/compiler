<?php
// 3+ level nested auto-vivification (was garbage — anonymous intermediate arrays)
$x = [];
$x["a"]["b"]["c"] = 42;
$x["a"]["b"]["d"] = 43;
$x["a"]["e"] = 7;
$m = [];
$m[1][2][3] = "deep";
$m[1][2][4] = "deep2";
$g = [];
$g["p"]["q"]["r"]["s"] = 99;
var_dump($x, $m, $g);
