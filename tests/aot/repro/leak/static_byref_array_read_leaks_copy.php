<?php
// Reading a function-static array through a by-reference return (`tab()["len"]`) leaks a copy of the whole array on every call.
/** @return array<string, mixed> */
function &tab(): array
{
    static $t = ['k' => [], 'len' => 0];
    return $t;
}
function put(int $v): void { $t = &tab(); $t['k'][] = $v; $t['len']++; }
function len(): int { return tab()['len']; }
$m0 = memory_get_peak_usage();
$s = 0;
for ($i = 0; $i < 3000; $i++) { put($i); $s += len(); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $s, "\n";
echo $mb < 8 ? "flat\n" : "LEAK\n";
