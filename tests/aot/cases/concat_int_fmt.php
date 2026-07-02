<?php
// Concat with an int operand is formatted straight into the fused buffer
// (no int_to_str temp). Cover zero, negatives, both-int, mixed, INT_MIN,
// and int self-append. Output must match the php interpreter exactly.
$parts = [];
for ($i = -3; $i <= 3; $i++) { $parts[] = "k" . $i; }
echo implode(",", $parts), "\n";
$a = 42; $b = -7;
echo $a . $b, "\n";
echo "x" . $a . "y" . $b . "z", "\n";
echo "n=" . 0 . "!", "\n";
echo "max=" . 9223372036854775807, "\n";
echo "min=" . (-9223372036854775807 - 1), "\n";
$s = "";
for ($i = 0; $i < 5; $i++) { $s .= "v" . $i . ";"; }
echo $s, "\n";
