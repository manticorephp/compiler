<?php
// @serial: a memory measurement.
// Calling closures out of one array whose returns differ in kind: the call's result is a cell,
// and an object / array result (which the closure ABI returns raw) used to be read as a double.

class Box { public function __construct(public int $v) {} }

foreach ([fn() => 1, fn() => new Box(7), fn() => [1, 2], fn() => "s", fn() => null, fn() => 1.5, fn() => false] as $mk) {
    $r = $mk();
    echo gettype($r), " ", is_object($r) ? get_class($r) . "(" . $r->v . ")" : json_encode($r), "\n";
}
$makers = ["box" => fn(int $n) => new Box($n), "none" => fn(int $n) => null];
foreach ($makers as $k => $f) {
    $o = $f(3);
    echo $k, " ", $o === null ? "NULL" : get_class($o), " ", $o instanceof Box ? $o->v : "-", "\n";
}
// The boxing of that result must not allocate for a result that is already a cell: the probe
// used to call box_int on every word and drop its heap box when the word was tagged.
$fs = [fn(int $i) => $i + 1, fn(int $i) => new Box($i)];
$sum = 0;
for ($i = 0; $i < 2000; $i++) { $sum += $fs[0]($i); }
$before = memory_get_usage();
for ($i = 0; $i < 500000; $i++) { $sum += $fs[0]($i); $o = $fs[1]($i); $sum += $o->v; }
echo $sum, " ", memory_get_usage() - $before < 3 * 1024 * 1024 ? "growth ok" : "leak", "\n";
