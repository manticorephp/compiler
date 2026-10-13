<?php
// A by-ref-returning call used as a bare statement leaks the +1 array copy it takes.
/** @return array<string, mixed> */
function &tab(): array
{
    static $t = ['k' => [], 'len' => 0];
    return $t;
}
function &sname(): string
{
    static $s = "hello world";
    return $s;
}
function put(int $v): void { $t = &tab(); $t['k'][] = $v; $t['len']++; }
function touch1(): void { tab(); sname(); }
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 3000; $i++) { put($i); touch1(); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
echo sname(), "\n";
echo $mb < 8 ? "flat\n" : "LEAK\n";
