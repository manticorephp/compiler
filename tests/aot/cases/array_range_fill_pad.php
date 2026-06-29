<?php
// Element-agnostic array builders: range, array_fill, array_pad.
echo implode(",", range(1, 5)), "\n";
echo implode(",", range(10, 2, 2)), "\n";
echo implode(",", range(0, -4, 2)), "\n";
echo implode(",", array_fill(0, 4, 7)), "\n";
var_dump(array_fill(2, 2, "x"));
echo implode(",", array_pad([1, 2], 5, 0)), "\n";
echo implode(",", array_pad([1, 2], -4, 9)), "\n";
echo implode(",", array_pad([1, 2, 3], 2, 0)), "\n";
