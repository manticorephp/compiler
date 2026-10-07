<?php
// A FIFO reports no length (ftell -1): file_get_contents reads it to EOF, as php does.
// Nothing is printed before the fork: the child would inherit unflushed stdout.
$fifo = sys_get_temp_dir() . '/mc_fgc_fifo_' . getmypid();
@unlink($fifo);
$made = posix_mkfifo($fifo, 0600);
$pid = pcntl_fork();
if ($pid === 0) {
    file_put_contents($fifo, str_repeat("line\n", 3) . "from child");
    exit(0);
}
var_dump($made);
var_dump(file_get_contents($fifo));
pcntl_waitpid($pid, $st);
echo "child exit: ", pcntl_wexitstatus($st), "\n";
unlink($fifo);
