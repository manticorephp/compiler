<?php
// sprintf() over a spread array literal holding a mixed (cell) value leaks the literal array.
// issue: #357
function t(array $p): int { $c = $p[0]; return strlen(sprintf("%s!", ...[$c])); }
$p = ["ab", 1];
t($p);
$b = memory_get_usage();
for ($i = 0; $i < 50000; $i++) { t($p); }
echo (memory_get_usage() - $b) < 500000 ? "flat" : "leak", "\n";
