<?php
// A by-ref argument fed from a spread lands in a throwaway slot
// issue: #61
function f(&$x) { $x = 5; }
$args = [1];
f(...$args);
echo json_encode($args), "\n";
