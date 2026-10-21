<?php
// memory_get_peak_usage() reports this process's own peak, not the peak of the process that exec'd it.
// Linux carries getrusage's ru_maxrss across execve: a child spawned by a 48 MB
// parent used to start at 48 MB, and its own growth stayed invisible below that.
if (($argv[1] ?? '') === 'child') {
    $b = memory_get_peak_usage();
    $s = str_repeat('y', 24 << 20);
    $g = memory_get_peak_usage() - $b;
    echo $b < (32 << 20) ? "own baseline" : "inherited baseline", "\n";
    echo $g > (16 << 20) ? "growth seen" : "growth hidden", "\n";
    exit(strlen($s) > 0 ? 0 : 1);
}
$big = str_repeat('x', 48 << 20);
$cmd = PHP_BINARY !== '' ? [PHP_BINARY, __FILE__, 'child'] : [$argv[0], 'child'];
$p = proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
echo stream_get_contents($pipes[1]);
fclose($pipes[1]);
echo "exit ", proc_close($p), " ", strlen($big), "\n";
