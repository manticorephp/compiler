<?php
// A hooked property read kept in a typed local or used as the left side of ?? leaks the fresh string the get hook built.
// issue: #491
class A { public string $s { get => str_repeat('x', 40) . 'y'; } }
$a = new A();
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 500000; $i++) {
    $z = $a->s;
    $w = $a->s ?? '';
}
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 4 ? "flat\n" : "LEAK\n";
