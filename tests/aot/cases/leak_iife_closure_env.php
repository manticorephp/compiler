<?php
// A closure literal invoked in place must give its env (and the captures) back after the call.
function iife(string $p): int {
    return (function () use ($p) { foreach ([1, 2] as $x) { if ($x == 5) { return 9; } } return strlen($p); })();
}
for ($i = 0; $i < 20000; $i++) { iife("/x"); }
$b = memory_get_usage();
for ($i = 0; $i < 200000; $i++) { iife("/x"); }
echo iife("/abc"), " ", (memory_get_usage() - $b) < 4000000 ? "flat" : "leak", "\n";
