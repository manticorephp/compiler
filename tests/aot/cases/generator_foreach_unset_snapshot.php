<?php
// A generator's foreach that unsets from the array it walks keeps its snapshot across a yield, and a generator destroyed mid-loop lets it go
function gen(array $keys): \Generator
{
    foreach ($keys as $i => $key) {
        unset($keys[$i]);
        yield $key => count($keys);
    }
    foreach ($keys as $key) { yield $key => -1; }
}
function fresh(int $n): array { $o = []; for ($i = 0; $i < $n; $i++) { $o[] = "v$i"; } return $o; }
function genFresh(int $n): \Generator
{
    foreach (fresh($n) as $i => $v) { yield $i => $v; }
}
foreach (gen(['a', 'b', 'c']) as $k => $v) { echo $k, $v, ' '; }
echo "\n";
foreach (gen(['x', 'y', 'z']) as $k => $v) { echo $k, $v, ' '; break; }
echo "\n";
foreach (genFresh(4) as $k => $v) { echo $k, $v, ' '; if ($k === 1) { break; } }
echo "\n";
$g = gen(['p', 'q']);
echo $g->current(), "\n";
unset($g);
$m = memory_get_usage();
for ($r = 0; $r < 2000; $r++) { foreach (gen(['m', 'n', 'o']) as $v) { break; } foreach (genFresh(3) as $v) { break; } }
echo memory_get_usage() - $m < 200000 ? "flat\n" : "grows\n";
