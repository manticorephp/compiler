<?php
// implode casts each element to string (int/float/bool/mixed), not just strings.
echo implode(",", [1, 2, 3]), "\n";
echo implode("-", [1.5, 2.5, 3.0]), "\n";
echo implode("/", ["a", "b", "c"]), "\n";
echo implode(" ", [10, "mid", 2.5]), "\n";
echo "[", implode(",", []), "]\n";
$nums = [];
for ($i = 1; $i <= 5; $i++) { $nums[] = $i * $i; }
echo implode("+", $nums), "\n";
