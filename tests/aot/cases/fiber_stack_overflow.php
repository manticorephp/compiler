<?php
// A fiber that runs off the bottom of its stack faults into the PROT_NONE guard
// page. Two things must hold: the process dies (rather than scribbling the heap
// and carrying on), and it SAYS why — before, it was a bare SIGSEGV (SIGBUS on
// Darwin, which is why the handler takes both) pointing at whatever function
// happened to touch the page.
//
// The overflow runs in a CHILD: a case that kills itself would just read as a
// failed case. The parent reports the wait status, which is the assertable part;
// the "fiber stack overflow" line itself goes to the child's STDERR from a signal
// handler on an alternate stack — the thread stack it would otherwise use is the
// one that just overflowed.
//
// Manticore-only (Fiber::setStackSize is superset), so nothing is printed before
// that call.

function deep(int $n): int
{
    $pad = [$n, $n, $n, $n];
    return $n <= 0 ? 0 : deep($n - 1) + $pad[0];
}

\Fiber::setStackSize(65536);
$pid = pcntl_fork();
if ($pid === 0) {
    $f = new \Fiber(function () { return deep(100000); });
    $f->start();
    exit(0);                      // only reached if the guard page did not fire
}

$status = 0;
pcntl_waitpid($pid, $status);
echo "died on a signal: ", pcntl_wifsignaled($status) ? 'yes' : 'no', "\n";
echo "exited cleanly: ", pcntl_wifexited($status) ? 'yes' : 'no', "\n";

// The parent is untouched: its own fibers still work, and the handler did not
// leave the guard armed for a stack nobody is on any more.
$g = new \Fiber(function () { return 'parent ok'; });
$g->start();
echo $g->getReturn(), "\n";
