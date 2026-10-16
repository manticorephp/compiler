<?php
// sort() orders numeric strings lexicographically; php compares numeric strings as numbers.
// issue: #283
$a = ["10", "9", "2"];
sort($a);
echo implode(",", $a), "\n";
