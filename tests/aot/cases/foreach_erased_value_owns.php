<?php
// array_shift on a foreach value taken from a decoded nested array crashes.
$r = json_decode('[[["t"]],[["a"],["q","1"]]]', true);
foreach ($r as $i => $parts) { $part = array_shift($parts); echo $i, ' ', json_encode($part), ' ', json_encode($parts), "\n"; }

// every in-place write to the loop variable must leave the source alone
function mutate(string $how): void {
    $r = json_decode('[[["t"]],[["a"],["q","1"]]]', true);
    foreach ($r as $p) {
        if ($how === 'push') { $p[] = 9; }
        elseif ($how === 'pop') { array_pop($p); }
        elseif ($how === 'unshift') { array_unshift($p, 9); }
        elseif ($how === 'key') { $p['k'] = 1; }
    }
    echo $how, ' ', json_encode($r), "\n";
}
foreach (['push', 'pop', 'unshift', 'key'] as $h) { mutate($h); }

// no growth per pass
$big = json_decode(json_encode(array_fill(0, 50, ['a', 'b', ['c']])), true);
$base = memory_get_peak_usage();
for ($i = 0; $i < 2000; $i++) { foreach ($big as $row) { $x = array_shift($row); $row[] = $x; } }
echo (memory_get_peak_usage() - $base) < 2000000 ? "flat\n" : "leak\n";
