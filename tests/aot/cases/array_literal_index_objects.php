<?php
// Indexing a temporary array literal of new objects never releases the objects.
class A { public string $p = 'x'; }
class B { public string $p = 'y'; }
function pick(int $i): object { return [new A, new B][$i]; }
$m0 = memory_get_peak_usage();
for ($i = 0; $i < 400000; $i++) { $o = pick($i % 2); }
$mb = (memory_get_peak_usage() - $m0) >> 20;
fwrite(STDERR, "grew {$mb} MB\n");
echo $mb < 8 ? "flat\n" : "leak\n";
