<?php
// The pool worker with no scheduler: raw pipes, raw jobs, blocking reads.
// NOP echoes a0; STAT fills a caller buffer; a failing STAT reports errno.

use Runtime\Libc;

$sub = Libc\calloc(2, 4);
$done = Libc\calloc(2, 4);
Libc\sys_pipe($sub);
Libc\sys_pipe($done);
$subR = peek_i32($sub, 0); $subW = peek_i32($sub, 4);
$doneR = peek_i32($done, 0); $doneW = peek_i32($done, 4);

echo "start: ", __mc_pool_start($subR, $doneW), "\n";
echo "start2: ", __mc_pool_start($subR, $doneW), "\n";

function run_job(int $subW, int $doneR, int $op, int $a0, int $a1 = 0): array {
    $job = Runtime\Libc\calloc(1, 64);
    poke_i64($job, 0, $op);
    poke_i64($job, 8, $a0);
    poke_i64($job, 16, $a1);
    $rec = Runtime\Libc\calloc(1, 8);
    poke_i64($rec, 0, ptr_to_int($job));
    Runtime\Libc\sys_write_ptr($subW, $rec, 8);
    Runtime\Libc\read($doneR, $rec, 8);
    $back = peek_i64($rec, 0);
    $out = [$back === ptr_to_int($job), peek_i64($job, 48), peek_i64($job, 56)];
    Runtime\Libc\free($rec);
    Runtime\Libc\free($job);
    return $out;
}

[$same, $ret, $err] = run_job($subW, $doneR, 0, 4242);
echo "nop: same=", $same ? 1 : 0, " ret=", $ret, "\n";

$path = Libc\strdup(__FILE__);
$st = Libc\calloc(1, 256);
[$same, $ret, $err] = run_job($subW, $doneR, 7, ptr_to_int($path), ptr_to_int($st));
echo "stat: ret=", $ret, " size>0=", peek_i64($st, __mc_stat_off(10)) > 0 ? 1 : 0, "\n";

$missing = Libc\strdup('/nonexistent/offload/x');
[$same, $ret, $err] = run_job($subW, $doneR, 7, ptr_to_int($missing), ptr_to_int($st));
echo "stat missing: ret=", $ret, " enoent=", $err === 2 ? 1 : 0, "\n";
