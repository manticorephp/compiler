<?php
// Three tasks read ONE directory handle at once: each pooled readdir copies its
// name inside the job, so a sibling's readdir refilling the DIR buffer never
// tears a name the other task has not read yet. scandir is one pooled job.
use function Async\async;
use function Async\spawn;

$dir = sys_get_temp_dir() . '/mc_offload_readdir_shared_' . getmypid();
mkdir($dir);
mkdir($dir . '_e');
$want = [];
for ($i = 0; $i < 1500; $i++) {
    $name = sprintf('%04d_', $i) . str_repeat(chr(97 + $i % 26), 40 + $i % 160);
    touch($dir . '/' . $name);
    $want[] = $name;
}
$want[] = '.';
$want[] = '..';
sort($want);

async(function () use ($dir, $want) {
    $intact = 0;
    for ($round = 0; $round < 4; $round++) {
        $h = opendir($dir);
        $reader = function () use ($h): array {
            $names = [];
            while (($n = readdir($h)) !== false) { $names[] = $n; }
            return $names;
        };
        $a = spawn($reader);
        $b = spawn($reader);
        $c = spawn($reader);
        $got = array_merge($a->await(), $b->await(), $c->await());
        closedir($h);
        sort($got);
        if ($got === $want) { $intact++; }
    }
    echo "readdir: 4 rounds of 3 readers, intact: ", $intact, "/4\n";

    $jobs = Async\stats()['offloaded'];
    $s = scandir($dir);
    echo "scandir jobs: ", Async\stats()['offloaded'] - $jobs, "\n";
    echo "scandir: ", count($s), " names, intact: ", $s === $want ? 'yes' : 'no', "\n";
    $d = scandir($dir, SCANDIR_SORT_DESCENDING);
    echo "scandir desc first: ", $d[0] === $want[count($want) - 1] ? 'yes' : 'no', "\n";
    echo "scandir missing: ", var_export(@scandir($dir . '/nope'), true), "\n";
    echo "scandir empty: ", implode(',', scandir($dir . '_e')), "\n";
});

foreach (scandir($dir) as $n) {
    if ($n !== '.' && $n !== '..') { unlink($dir . '/' . $n); }
}
rmdir($dir);
rmdir($dir . '_e');
