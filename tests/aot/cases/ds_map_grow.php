<?php
use Manticore\Ds\Map;

$m = new Map();
for ($i = 0; $i < 1000000; $i++) { $m->set($i * 7919, $i); }
$sum = 0;
for ($i = 0; $i < 1000000; $i += 997) { $sum += $m->get($i * 7919); }
echo count($m), ' ', $sum, "\n";
$s = new Map();
for ($i = 0; $i < 200000; $i++) { $s->set("key$i", $i); }
for ($i = 0; $i < 200000; $i += 2) { $s->remove("key$i"); }
echo count($s), ' ', $s->get('key199999'), ' ', $s->has('key4') ? 'y' : 'n', "\n";
$ok = 0;
for ($i = 0; $i < 200000; $i++) { if ($s->has("key$i") === ($i % 2 === 1)) { $ok++; } }
echo $ok, "\n";
