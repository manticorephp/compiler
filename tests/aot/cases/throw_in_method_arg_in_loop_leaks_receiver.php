<?php
// A throw while evaluating a method call's argument inside a loop leaks the receiver object.
final class Bag { public array $a = []; public function set(string $k, string $v): void { $this->a[$k] = $v; } }
function fill(int $i): int
{
    $r = new Bag();
    try {
        foreach (['k0', 'k1', 'k2'] as $k) { $r->set($k, $k === 'k2' ? throw new RuntimeException('x') : str_repeat('v', 200) . $i); }
    } catch (RuntimeException $e) { return 1; }
    return 0;
}
for ($i = 0; $i < 200; $i++) { fill($i); }
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 20000; $i++) { fill($i); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 2 ? "flat\n" : "LEAK\n";
