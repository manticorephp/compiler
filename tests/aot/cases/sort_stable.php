<?php
// merge sort is STABLE (PHP 8+ usort/sort are): equal compare-keys keep input order.
$pairs = [[3,"a"],[1,"b"],[3,"c"],[1,"d"],[2,"e"],[3,"f"],[2,"g"]];
usort($pairs, fn($x, $y) => $x[0] - $y[0]);
$p = []; foreach ($pairs as $pr) { $p[] = $pr[0] . $pr[1]; }
echo implode(",", $p), "\n";

$a = [5, 2, 8, 1, 9, 3, 7, 4, 6, 0, 2, 8]; sort($a); echo implode(",", $a), "\n";
$b = [5, 2, 8, 1, 9, 3]; rsort($b); echo implode(",", $b), "\n";
$s = ["pear", "apple", "fig", "apple", "cherry"]; sort($s); echo implode(",", $s), "\n";
$w = ["bbb", "a", "cc", "dddd", "e"]; usort($w, fn($x, $y) => strlen($x) - strlen($y)); echo implode(",", $w), "\n";
$one = [42]; sort($one); echo implode(",", $one), "\n";
