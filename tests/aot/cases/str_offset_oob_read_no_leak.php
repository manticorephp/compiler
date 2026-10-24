<?php
// Reading a string offset past the end of a string leaks a fresh empty string per read.
function t(array $p): int { $c = $p['v']; $x = @$c[0]; return strlen($x); }
$p = ['k' => 1, 'v' => ''];
t($p);
$b = memory_get_usage();
for ($i = 0; $i < 50000; $i++) { t($p); }
echo (memory_get_usage() - $b) < 500000 ? "flat" : "leak", "\n";
