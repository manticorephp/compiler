<?php
// A local aliased with =& is dropped from ownership tracking and its string leaks.
// issue: #395
function t(int $i): int { $y = "s" . $i . str_repeat("x", 40); $z = &$y; $z = $z . "!"; return strlen($y); }
t(1);
$b = memory_get_usage();
for ($i = 0; $i < 50000; $i++) { t($i); }
echo (memory_get_usage() - $b) < 500000 ? "flat" : "leak", "\n";
