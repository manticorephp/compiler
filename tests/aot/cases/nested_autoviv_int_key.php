<?php
// int-keyed 2-level auto-viv (was a pre-existing SIGSEGV)
$grid = [];
$grid[2][3] = 7;
$grid[2][4] = 8;
$grid[5][0] = 9;
echo $grid[2][3], " ", $grid[2][4], " ", $grid[5][0], "\n";
var_dump($grid);
