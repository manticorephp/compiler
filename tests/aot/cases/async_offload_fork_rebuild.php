<?php
// The pool is per process: the parent uses it first, then forked workers each
// rebuild their own and read a file inside async().
use function Async\async;
function pooled_read(): string {
    return async(function () {
        $ok = strlen(file_get_contents(__FILE__)) > 0;
        return ($ok ? "read" : "fail") . " threads=" . Async\stats()['pool_threads'];
    });
}
echo "parent: ", pooled_read(), "\n";
$codes = [];
for ($i = 0; $i < 2; $i++) {
    // stdout is a buffered FILE* here (php's CLI writes it straight through) and
    // pcntl_fork() does not flush it yet: without this the child inherits the
    // unflushed "parent:" line and prints it a second time.
    fflush(STDOUT);
    $pid = pcntl_fork();
    if ($pid === 0) {
        exit(pooled_read() === "read threads=4" ? 0 : 1);
    }
    pcntl_waitpid($pid, $st);
    $codes[] = pcntl_wexitstatus($st);
}
foreach ($codes as $i => $c) { echo "child $i: ", $c === 0 ? "ok" : "fail", "\n"; }
echo "parent again: ", pooled_read(), "\n";
