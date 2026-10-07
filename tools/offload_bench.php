<?php
/**
 * Cost of the blocking-offload pool on small calls: the same work outside and
 * inside async(), where every covered call is one pool job.
 *
 *   bin/manticore compile tools/offload_bench.php -o /tmp/offload_bench
 *   /tmp/offload_bench [dir]
 *
 * Prints wall ms per case: 1M 16-byte fwrites, a 50k-entry scandir, 10k
 * file_get_contents of a 64-byte file. The numbers are in docs/async.md.
 */

function bench_fwrite(string $dir): void
{
    $h = fopen("$dir/w.bin", 'w');
    $s = str_repeat('x', 16);
    for ($i = 0; $i < 1000000; $i++) {
        fwrite($h, $s);
    }
    fclose($h);
}

function bench_scandir(string $dir): int
{
    return count(scandir("$dir/many"));
}

function bench_read(string $dir): int
{
    $n = 0;
    for ($i = 0; $i < 10000; $i++) {
        $n += strlen(file_get_contents("$dir/small.txt"));
    }
    return $n;
}

function bench_run(string $label, string $dir): void
{
    foreach (['fwrite 1M x 16 B', 'scandir 50k', 'file_get_contents 10k'] as $k => $case) {
        $t = hrtime(true);
        if ($k === 0) { bench_fwrite($dir); }
        if ($k === 1) { bench_scandir($dir); }
        if ($k === 2) { bench_read($dir); }
        printf("%-8s %-24s %9.1f ms\n", $label, $case, (hrtime(true) - $t) / 1e6);
    }
}

$dir = ($argv[1] ?? sys_get_temp_dir()) . '/mc_offload_bench_' . getmypid();
mkdir($dir);
mkdir("$dir/many");
for ($i = 0; $i < 50000; $i++) {
    touch("$dir/many/entry_$i");
}
file_put_contents("$dir/small.txt", str_repeat('y', 64));

bench_run('inline', $dir);
Async\async(function () use ($dir) { bench_run('async', $dir); });

foreach (scandir("$dir/many") as $n) {
    if ($n !== '.' && $n !== '..') { unlink("$dir/many/$n"); }
}
rmdir("$dir/many");
unlink("$dir/small.txt");
unlink("$dir/w.bin");
rmdir($dir);
