<?php
// (object) cast of an int-valued array literal leaks its property bag.
// issue: #261
function t(): int { $x = (object)["a" => 1]; return 1; }
t();
$b = memory_get_usage();
for ($i = 0; $i < 50000; $i++) { t(); }
echo (memory_get_usage() - $b) < 500000 ? "flat" : "leak", "\n";
