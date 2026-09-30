<?php
// A daemonizing child closes every inherited fd and opens its own files, which
// take the numbers the parent's pool pipes had. Its first pooled call rebuilds
// the pool and must close only fds that still name those pipes, not its files.
use function Async\async;

$base = sys_get_temp_dir() . '/mc_offload_fork_daemon_' . getmypid();
echo "parent: ", async(fn() => strlen(file_get_contents(__FILE__)) > 0 ? "read" : "fail"), "\n";
fflush(STDOUT);
$pid = pcntl_fork();
if ($pid === 0) {
    for ($fd = 3; $fd < 64; $fd++) {
        Runtime\Libc\sys_close($fd);
    }
    $hs = [];
    for ($i = 0; $i < 8; $i++) {
        $hs[] = fopen("$base.$i", 'w');
    }
    $read = async(fn() => strlen(file_get_contents(__FILE__)) > 0);
    $ok = 0;
    foreach ($hs as $i => $h) {
        if (fwrite($h, "x") === 1 && fflush($h) && fclose($h)) {
            clearstatcache();
            if (filesize("$base.$i") === 1) { $ok++; }
        }
    }
    exit($read && $ok === 8 ? 0 : 1);
}
pcntl_waitpid($pid, $st);
echo "child: ", pcntl_wexitstatus($st) === 0 ? "files intact" : "fail", "\n";
for ($i = 0; $i < 8; $i++) {
    @unlink("$base.$i");
}
