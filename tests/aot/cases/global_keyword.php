<?php
// The global keyword binds function-local names to top-level variables.
$counter = 0;
$total = 100;
function tick() { global $counter; $counter++; return $counter; }
function reads() { global $total; return $total * 2; }
function writes() { global $counter; $counter = $counter + 10; }
echo tick(), tick(), tick(), "\n";
echo reads(), "\n";
writes();
echo $counter, "\n";
