<?php
// A fiber stack that cannot be mapped must be a FiberError, not a SIGSEGV.
// mmap's MAP_FAILED used to flow back into PHP as -1 and get a context built on
// it, so running out of address space crashed with nothing to read.
//
// The failure is provoked in a CHILD, and by capping RLIMIT_AS first rather than
// by asking for an absurd size: Docker on Apple Silicon runs amd64 through
// Rosetta, and a 1 EiB request kills the TRANSLATOR ("could not find free space
// for allocation size 1000000000000000") before the kernel ever gets to answer
// MAP_FAILED. A modest request under a small limit fails the way real exhaustion
// does. Darwin does not enforce RLIMIT_AS (its header aliases AS to RSS), so
// there the child falls through to the absurd size, which works fine natively.
//
// Manticore-only (Fiber::setStackSize is superset — Zend has the fiber.stack_size
// ini instead), so this file must print NOTHING before that call: difftest
// classifies a case as PHP-SKIP by "php produced no stdout".

\Fiber::setStackSize(262144);

// Docker Desktop translates x86_64 through Rosetta, and Rosetta cannot express a
// failed mapping AT ALL: an RLIMIT_AS cap kills the translator outright, a 1 TiB
// stack maps successfully, and 128 TiB kills it again. Detect that and say so
// rather than assert something the platform cannot produce — the `.sed` sidecar
// folds the two lines into one. Native arm64, macOS and real x86 all probe for real.
$maps = @file_get_contents('/proc/self/maps');
if ($maps !== false && stripos((string)$maps, 'rosetta') !== false) {
    echo "mapping failure: not probed (translated host)\n";
    $pid = -1;
} else {
$pid = pcntl_fork();
if ($pid === 0) {
    // 512 MiB of address space — comfortably above what the program already maps —
    // then ask for a 2 GiB stack: it cannot fit, and nothing has to reserve an
    // impossible range for us to find out.
    posix_setrlimit(POSIX_RLIMIT_AS, 536870912, 536870912);
    \Fiber::setStackSize(2147483648);
    try {
        $f = new \Fiber(function () { return 1; });
        $f->start();
    } catch (\FiberError $e) {
        exit(7);
    } catch (\Throwable $e) {
        exit(8);
    }
    // The limit was not enforced (Darwin). Fall back to a size no address space
    // has room for; natively that is a plain MAP_FAILED.
    \Fiber::setStackSize(1 << 60);
    try {
        $g = new \Fiber(function () { return 1; });
        $g->start();
    } catch (\FiberError $e) {
        exit(7);
    }
    exit(0);   // allocated it — the failure path is not being exercised at all
}

$status = 0;
pcntl_waitpid($pid, $status);
echo 'mapping failure: ', pcntl_wexitstatus($status) === 7 ? 'FiberError' : 'NOT reported', "\n";
}

// The parent is untouched: a normal size still works, and it is the one that was set.
echo \Fiber::stackSize(), "\n";
$g = new \Fiber(function (int $x) {
    $y = \Fiber::suspend($x * 2);
    return $y + 1;
});
echo $g->start(21), "\n";
echo $g->resume(100), "\n";
echo $g->getReturn(), "\n";

// A size below the floor is clamped to it, not accepted: the low 16 KiB of every
// stack is a PROT_NONE guard page.
\Fiber::setStackSize(1024);
echo \Fiber::stackSize(), "\n";
$h = new \Fiber(fn() => 'ok');
$h->start();
echo $h->getReturn(), "\n";
