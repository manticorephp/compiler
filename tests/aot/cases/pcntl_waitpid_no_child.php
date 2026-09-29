<?php
// waitpid(2) answers -1 (ECHILD) when there is nothing to reap. The bind lacked
// #[CType('int')], so on Linux the 32-bit -1 came back as 4294967295 and a
// `while (pcntl_waitpid(-1, $st) > 0)` reap loop never ended.
$st = 0;
var_dump(pcntl_waitpid(-1, $st));
var_dump(pcntl_waitpid(-1, $st, WNOHANG));
$pid = pcntl_fork();
if ($pid === 0) { exit(3); }
$n = 0;
while (($r = pcntl_waitpid(-1, $st)) > 0) { $n++; var_dump($r === $pid, pcntl_wexitstatus($st)); }
var_dump($n, $r);
