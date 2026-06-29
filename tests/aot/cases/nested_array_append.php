<?php
// Root-cause fix: `$m[k][] = v` / `$m[k][i] = v` must write the
// (possibly realloced) inner array back into its parent.
$m = [];
$m["a"] = [];
$m["a"][] = 1;
$m["a"][] = 2;
$m["b"] = [10];
$m["b"][] = 20;
echo count($m["a"]), " ", count($m["b"]), "\n";
echo $m["a"][0], $m["a"][1], "\n";

$g = [];
$g[5] = [];
$g[5][] = 100;
$g[5][] = 200;
echo array_sum($g[5]), "\n";

// nested set by index
$grid = [];
$grid[0] = [0, 0];
$grid[0][1] = 9;
echo $grid[0][0], $grid[0][1], "\n";
