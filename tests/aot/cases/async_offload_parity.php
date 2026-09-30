<?php
// Every routed call prints the same inside async() (pool) and outside it (inline),
// failures included. Plus two tasks writing one handle concurrently.
use function Async\async;
use function Async\spawn;

function fs_script(string $dir): void {
    @mkdir($dir);
    echo "mkdir again: ", var_export(@mkdir($dir), true), "\n";
    echo "put: ", file_put_contents("$dir/a.txt", "one\n"), "\n";
    echo "append: ", file_put_contents("$dir/a.txt", "two\n", FILE_APPEND), "\n";
    echo "get: ", json_encode(file_get_contents("$dir/a.txt")), "\n";
    echo "missing: ", var_export(@file_get_contents("$dir/nope"), true), "\n";
    $h = fopen("$dir/b.bin", 'w');
    fwrite($h, str_repeat('x', 100000));
    fflush($h);
    fclose($h);
    echo "size: ", filesize("$dir/b.bin"), " exists: ", var_export(file_exists("$dir/b.bin"), true), "\n";
    $h = fopen("$dir/b.bin", 'r');
    echo "fread: ", strlen(fread($h, 70000)), "\n";
    fclose($h);
    echo "stat missing: ", var_export(@stat("$dir/nope"), true), "\n";
    rename("$dir/b.bin", "$dir/c.bin");
    $list = scandir($dir);
    echo "scandir: ", implode(',', $list), "\n";
    unlink("$dir/a.txt");
    unlink("$dir/c.bin");
    echo "unlink missing: ", var_export(@unlink("$dir/nope"), true), "\n";
    rmdir($dir);
    echo "rmdir gone: ", var_export(is_dir($dir), true), "\n";
}

$base = sys_get_temp_dir() . '/mc_offload_parity_' . getmypid();
echo "-- plain\n";
fs_script($base . '_p');
echo "-- async\n";
async(function () use ($base) {
    fs_script($base . '_a');
    $h = fopen($base . '_shared', 'w');
    $a = spawn(function () use ($h) { for ($i = 0; $i < 200; $i++) { fwrite($h, "A"); } });
    $b = spawn(function () use ($h) { for ($i = 0; $i < 200; $i++) { fwrite($h, "B"); } });
    Async\awaitAll($a, $b);
    fclose($h);
    $s = file_get_contents($base . '_shared');
    echo "shared: len=", strlen($s), " A=", substr_count($s, 'A'), " B=", substr_count($s, 'B'), "\n";
    echo "offloaded>0: ", Async\stats()['offloaded'] > 0 ? 1 : 0, "\n";
    unlink($base . '_shared');
});
