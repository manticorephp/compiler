<?php
// A closure literal invoked in place must give its env (and the captures) back after the call.
function iife(string $p): int {
    return (function () use ($p) { foreach ([1, 2] as $x) { if ($x == 5) { return 9; } } return strlen($p); })();
}
iife("/x");
$b = memory_get_usage();
for ($i = 0; $i < 50000; $i++) { iife("/x"); }
echo iife("/abc"), " ", (memory_get_usage() - $b) < 500000 ? "flat" : "leak", "\n";
