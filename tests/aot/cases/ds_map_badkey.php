<?php
use Manticore\Ds\{Map, Set};
$m = new Map(); $s = new Set();
foreach ([1.5, true, null, [1]] as $bad) {
    foreach ([fn() => $m->set($bad, 1), fn() => $m->get($bad), fn() => $m->has($bad), fn() => $s->add($bad), fn() => $m->remove($bad),
              function () use ($m, $bad) { unset($m[$bad]); }, fn() => $s->has($bad), fn() => $s->remove($bad)] as $f) {
        try { $f(); echo "no throw\n"; } catch (TypeError $e) { echo $e->getMessage(), "\n"; }
    }
}
echo count($m), count($s), "\n";
