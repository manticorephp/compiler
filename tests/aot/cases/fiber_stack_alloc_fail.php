<?php
// A fiber stack that cannot be mapped must be a FiberError, not a SIGSEGV.
// mmap's MAP_FAILED used to flow back into PHP as -1 and get a context built on
// it, so running out of address space crashed with nothing to read.
//
// Manticore-only (Fiber::setStackSize is superset — Zend has the fiber.stack_size
// ini instead), so this file must print NOTHING before that call: difftest
// classifies a case as PHP-SKIP by "php produced no stdout", and a literal echoed
// before the fatal turns the skip into a DIFF.

// 1 EiB. Deliberately far past any host's user address space: Darwin happily
// reserves 64 TiB of VA lazily, and a 48-bit arm64 Linux would take 128 TiB, so a
// merely large number does not fail everywhere.
\Fiber::setStackSize(1 << 60);
try {
    $f = new \Fiber(function () { return 1; });
    $f->start();
    echo "unreachable: allocated 64 TiB\n";
} catch (\FiberError $e) {
    echo "FiberError\n";
}

// The failure must not have poisoned the process: a normal size still works, and
// the stack it takes is the one that was set.
\Fiber::setStackSize(262144);
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
