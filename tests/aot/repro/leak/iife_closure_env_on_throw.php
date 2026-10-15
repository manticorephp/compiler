<?php
// A closure literal invoked in place and thrown through leaks its env on the unwind path.
// issue: #260
function t(string $p): int {
    try { (function () use ($p) { throw new \RuntimeException("nf $p"); })(); } catch (\RuntimeException $e) { return 1; }
    return 0;
}
t("/x");
$b = memory_get_usage();
for ($i = 0; $i < 50000; $i++) { t("/x"); }
echo (memory_get_usage() - $b) < 500000 ? "flat" : "leak", "\n";
