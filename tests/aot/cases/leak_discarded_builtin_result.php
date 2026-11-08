<?php
// A minting builtin called as a statement (`json_decode('[]');`) drops its result (Laravel JsonResponse::setData).
function f(): int { json_decode('[1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30]'); str_repeat('ab', 300); return json_last_error(); }
echo f(), "\n";
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 50000; $i++) { f(); }
echo ((memory_get_peak_usage() - $m0) >> 20) < 8 ? "flat\n" : "LEAK\n";
