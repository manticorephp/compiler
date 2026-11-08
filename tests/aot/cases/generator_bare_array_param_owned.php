<?php
// A generator owns a bare `array` param: a literal the caller drops right after the
// call was gone by the first resume, and an element of it read as null.
class PP { public function __construct(public int $n = 7) {} public function g() { return $this->n; } }
function read(array $c) { yield get_debug_type($c['p'] ?? null); yield $c['p']->g(); }
function grow(array $c) { $c['q'] = new PP(8); yield $c['p']->g(); yield $c['q']->g(); unset($c['p']); yield count($c); }
function abandon(array $c) { yield $c['p']->g(); yield 'never'; }
foreach (read(['p' => new PP]) as $v) { echo $v, "\n"; }
foreach (grow(['p' => new PP(9)]) as $v) { echo $v, "\n"; }
$keep = ['p' => new PP(3)];
foreach (grow($keep) as $v) { echo $v, "\n"; }
echo count($keep), $keep['p']->g(), "\n";
foreach (abandon(['p' => new PP(4)]) as $v) { echo $v, "\n"; break; }
$before = 0;
for ($i = 0; $i < 20000; $i++) {
    foreach (read(['p' => new PP($i), 'pad' => str_repeat('x', 200)]) as $v) {}
    foreach (abandon(['p' => new PP($i), 'pad' => str_repeat('y', 200)]) as $v) { break; }
    if ($i === 2000) { $before = memory_get_peak_usage(); }
}
echo memory_get_peak_usage() - $before < 1000000 ? "flat\n" : "leak\n";
