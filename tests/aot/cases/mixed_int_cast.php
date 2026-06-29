<?php
// (int)$cell — convert a mixed value to int by tag (string parses, float
// truncates, bool 0/1, int passes, array empties→0/1).
function toi(mixed $x): int { return (int)$x; }
echo toi("7"), "\n";
echo toi(42), "\n";
echo toi(3.9), "\n";
echo toi(true), "\n";
echo toi("123abc"), "\n";
echo toi([1, 2]), "\n";
echo toi([]), "\n";
