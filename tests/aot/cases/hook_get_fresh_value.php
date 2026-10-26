<?php
// a get hook returning a fresh string does not leak through typed, union or erased receivers
final class A { public int $n = 1; public string $s { get => str_repeat('x', 16384) . $this->n; } }
final class C { public string $s = 'c'; }
function make(): A|false { return new A(); }
function viaMixed(mixed $o): int { return strlen($o->s); }
$a = new A();
$u = make();
$m0 = memory_get_peak_usage();
$t = 0;
for ($i = 0; $i < 2000; $i++) {
    $t += strlen($a->s) + strlen($u->s) + viaMixed($i % 2 ? $a : new C());
}
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $t > 0 && $mb < 8 ? "flat\n" : "LEAK\n";
