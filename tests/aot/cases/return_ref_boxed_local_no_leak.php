<?php
function viaArr(int $i): string { $y = "s" . $i . str_repeat("x", 40); $a = [&$y]; return $y; }
function viaCapture(int $i): string { $y = "c" . $i . str_repeat("x", 40); $f = function () use (&$y) { return 1; }; return $y; }
function selfAssign(int $i): string { $y = "a" . $i . str_repeat("x", 40); $a = [&$y]; $a[0] = $a[0]; $a[0] = $y; return $y; }
echo viaArr(1), " ", viaCapture(2), " ", selfAssign(3), "\n";
// The probe reads the peak RSS, which moves in whole pages for reasons that are
// not a leak (heap top, trim, huge pages). A warm-up batch lets the allocator
// settle; the measured batch is long enough that a per-call leak of the ~50-byte
// string (>= 2 MB) dwarfs the 200 KB budget.
foreach (['viaArr', 'viaCapture', 'selfAssign'] as $fn) {
    for ($i = 0; $i < 20000; $i++) { $fn($i); }
    $b = memory_get_usage();
    for ($i = 0; $i < 40000; $i++) { $fn($i); }
    echo $fn, " ", (memory_get_usage() - $b) < 200000 ? "flat" : "leak", "\n";
}
