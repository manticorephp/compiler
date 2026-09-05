<?php
// name → alloc/free, from a [CLASSMAP] + [CLASS] stderr stream
$map = []; $cls = [];
$h = fopen($argv[1], 'r');
while (($l = fgets($h)) !== false) {
    if (strncmp($l, '[CLASSMAP] ', 11) === 0) {
        $p = explode(' ', trim($l), 3);
        if (count($p) === 3) { $map[(int)$p[1]] = $p[2]; }
    } elseif (strncmp($l, '[CLASS] ', 8) === 0) {
        if (preg_match('/^\[CLASS\] idx=(\d+) alloc=(\d+) free=(\d+)/', $l, $m)) {
            $cls[(int)$m[1]] = [(int)$m[2], (int)$m[3]];
        }
    }
}
fclose($h);
$want = $argv[2] ?? '';
$rows = [];
foreach ($cls as $id => $af) {
    $n = $map[$id] ?? ('#' . $id);
    if ($want !== '' && !str_contains($n, $want)) { continue; }
    $rows[] = [$n, $af[0], $af[1]];
}
usort($rows, fn($a, $b) => ($b[1] - $b[2]) <=> ($a[1] - $a[2]));
$tot = 0;
foreach (array_slice($rows, 0, 15) as $r) {
    printf("%-44s alloc=%-9d free=%-9d live=%d\n", $r[0], $r[1], $r[2], $r[1] - $r[2]);
}
foreach ($rows as $r) { $tot += $r[1] - $r[2]; }
echo "TOTAL live objects: $tot\n";
