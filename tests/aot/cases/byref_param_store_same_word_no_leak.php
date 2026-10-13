<?php

// A whole store through a by-ref parameter gives the old value back even when
// the new value is the same buffer (`$n = $a; $a = $n`): the store took its own
// count, and skipping the release of the "same word" leaked the array per call.
// `$a = $a` takes no count and must not release. @serial: a memory measurement.

/** @param array<string, list<string>> $a */
function roundtrip(array &$a): void { $n = $a; $a = $n; }

/** @param array<string, list<string>> $a */
function self_store(array &$a): void { $a = $a; }

$x = ['host' => ['localhost']];
roundtrip($x);
self_store($x);
echo $x['host'][0], "\n";

$base = 0;
for ($i = 0; $i < 200000; $i++) {
    $a = [];
    foreach (['HOST', 'ACCEPT', 'USER_AGENT'] as $k) { $a[strtolower($k)] = [$k]; }
    roundtrip($a);
    self_store($a);
    if ($i === 1000) { $base = memory_get_peak_usage(); }
}
echo $a['accept'][0], "\n";
echo memory_get_peak_usage() - $base < 3000000 ? "flat\n" : "grows\n";
