<?php
function viaArr(int $i): string { $y = "s" . $i . str_repeat("x", 40); $a = [&$y]; return $y; }
function viaCapture(int $i): string { $y = "c" . $i . str_repeat("x", 40); $f = function () use (&$y) { return 1; }; return $y; }
function selfAssign(int $i): string { $y = "a" . $i . str_repeat("x", 40); $a = [&$y]; $a[0] = $a[0]; $a[0] = $y; return $y; }
echo viaArr(1), " ", viaCapture(2), " ", selfAssign(3), "\n";
foreach (['viaArr', 'viaCapture', 'selfAssign'] as $fn) {
    $fn(1);
    $b = memory_get_usage();
    for ($i = 0; $i < 20000; $i++) { $fn($i); }
    echo $fn, " ", (memory_get_usage() - $b) < 200000 ? "flat" : "leak", "\n";
}
