<?php

// The MECHANISM half of ext/pcntl + ext/posix. Signals and process control, as
// scalars only — no callables, no arrays built here and handed back out, no
// statics holding anything with a refcount. The handler REGISTRY and the
// php-facing pcntl_*/posix_* functions live in prelude/pcntl.php instead, and
// that split is not style:
//
//   ⚠ An array BUILT INSIDE the stdlib does not survive being parked in a
//     static and returned to user code ({@see __mc_http_headers_store}). A
//     signal registry holds CALLABLES, which cannot be flattened to a
//     refcount-free scalar the way that workaround does — so it has to live in
//     a module the user's own program compiles, i.e. the prelude.
//
// ⚠ No SIG* / SIG_BLOCK / WNOHANG constant may be NAMED in this file. They are
// host-divergent and resolved at compile time in LowerPrelude via a libc probe;
// naming one here makes that probe reachable from a path the stdlib itself
// walks, and the Zend cold seed (where the libc bindings are empty stubs) dies.
// Use the numeric __mc_sig_const() selector, exactly as Sockets.php does.
//
// WHY BLOCK-AND-REAP rather than a handler: a C function pointer cannot be a PHP
// closure, so `signal(2)` can only ever be given SIG_DFL/SIG_IGN here. The rest
// of the design follows from that — block the signal so its default action
// cannot fire and the kernel keeps it PENDING, then collect it at a safe point
// with sigpending(2) + sigwait(2). php's own pcntl is likewise deferred (the C
// handler only sets a flag; the PHP callback runs at pcntl_signal_dispatch()),
// so the observable behaviour matches. sigtimedwait is deliberately NOT used:
// Darwin does not have it.

/**
 * Host-divergent signal constants, runtime-selected (mirrors {@see __mc_sock_const}).
 * Ints only, never an array — an assoc built in the stdlib faults across a call
 * boundary. $which:
 *   0 SIG_BLOCK  1 SIG_UNBLOCK  2 SIG_SETMASK  3 sizeof(sigset_t)  4 NSIG
 *
 * MEASURED, not recalled: Darwin arm64 <signal.h> vs Linux glibc 13 AND musl
 * 3.20 (which agree with each other on every value here).
 */
function __mc_sig_const(int $which): int
{
    static $ready = 0;
    static $block = 0;
    static $unblock = 0;
    static $setmask = 0;
    static $size = 0;
    static $nsig = 0;

    if ($ready === 0) {
        $isDarwin = \__mc_host_is_darwin();
        $block = $isDarwin ? 1 : 0;
        $unblock = $isDarwin ? 2 : 1;
        $setmask = $isDarwin ? 3 : 2;
        $size = $isDarwin ? 4 : 128;
        $nsig = $isDarwin ? 32 : 65;
        $ready = 1;
    }

    if ($which === 0) { return $block; }
    if ($which === 1) { return $unblock; }
    if ($which === 2) { return $setmask; }
    if ($which === 3) { return $size; }
    return $nsig;
}

/**
 * A zeroed sigset_t buffer. Always the 128-byte Linux superset (Darwin needs 4)
 * so one size fits both and the host's own sigemptyset decides the layout.
 */
function __mc_sigset_alloc(): ?\Ffi\Ptr
{
    $set = \Runtime\Libc\calloc(1, 128);
    if ($set === null) {
        return null;
    }
    \Runtime\Libc\sys_sigemptyset($set);
    return $set;
}

/** Change the process signal mask for ONE signal. $how is __mc_sig_const(0|1|2). */
function __mc_sig_mask_one(int $how, int $signo): bool
{
    $set = \__mc_sigset_alloc();
    if ($set === null) {
        return false;
    }
    \Runtime\Libc\sys_sigaddset($set, $signo);
    $rc = \Runtime\Libc\sys_sigprocmask($how, $set, \int_to_ptr(0));
    \Runtime\Libc\free($set);
    return $rc === 0;
}

/**
 * Block $signo: its default action can no longer fire and the kernel holds it
 * PENDING until {@see __mc_sig_take_pending} collects it.
 */
function __mc_sig_block(int $signo): bool
{
    return \__mc_sig_mask_one(\__mc_sig_const(0), $signo);
}

/** Unblock $signo — the disposition (default or ignore) takes over again. */
function __mc_sig_unblock(int $signo): bool
{
    return \__mc_sig_mask_one(\__mc_sig_const(1), $signo);
}

/**
 * Apply a mask change for a whole set and return the PREVIOUS mask as a BIT SET
 * (bit `signo - 1`), not an array — an array built here would not survive the
 * boundary. Signals are 1..64 on both hosts, so an i64 holds every one.
 *
 * @param int[] $signos
 */
function __mc_sig_procmask(int $how, array $signos): int
{
    $set = \__mc_sigset_alloc();
    if ($set === null) {
        return 0;
    }
    foreach ($signos as $s) {
        \Runtime\Libc\sys_sigaddset($set, $s);
    }
    $old = \Runtime\Libc\calloc(1, 128);
    if ($old === null) {
        \Runtime\Libc\free($set);
        return 0;
    }
    $rc = \Runtime\Libc\sys_sigprocmask($how, $set, $old);
    $bits = 0;
    if ($rc === 0) {
        $n = \__mc_sig_const(4);
        for ($s = 1; $s < $n; $s = $s + 1) {
            if (\Runtime\Libc\sys_sigismember($old, $s) === 1) {
                $bits = $bits | (1 << ($s - 1));
            }
        }
    }
    \Runtime\Libc\free($set);
    \Runtime\Libc\free($old);
    return $bits;
}

/**
 * Collect ONE pending signal out of $signos, or 0 when none is pending.
 *
 * Every signal in $signos is already BLOCKED (that is what registering a handler
 * does), so a pending one is guaranteed to be sitting in the process's pending
 * set and `sigwait` on it returns immediately rather than waiting.
 *
 * @param int[] $signos
 */
function __mc_sig_take_pending(array $signos): int
{
    if (\count($signos) === 0) {
        return 0;
    }
    $pend = \__mc_sigset_alloc();
    if ($pend === null) {
        return 0;
    }
    $hit = 0;
    if (\Runtime\Libc\sys_sigpending($pend) === 0) {
        foreach ($signos as $s) {
            if (\Runtime\Libc\sys_sigismember($pend, $s) === 1) {
                $hit = $s;
                break;
            }
        }
    }
    \Runtime\Libc\free($pend);
    if ($hit === 0) {
        return 0;
    }

    $set = \__mc_sigset_alloc();
    if ($set === null) {
        return 0;
    }
    \Runtime\Libc\sys_sigaddset($set, $hit);
    $out = \Runtime\Libc\calloc(1, 8);
    if ($out === null) {
        \Runtime\Libc\free($set);
        return 0;
    }
    $rc = \Runtime\Libc\sys_sigwait($set, $out);
    $got = $rc === 0 ? \peek_i32($out, 0) : 0;
    \Runtime\Libc\free($set);
    \Runtime\Libc\free($out);
    return $got;
}

/**
 * Set a REAL disposition and stop tracking the signal: $how is 0 for SIG_DFL or
 * 1 for SIG_IGN (php's own constant values). Unblocks it too, so the
 * disposition can actually take effect.
 */
function __mc_sig_disposition(int $signo, int $how): bool
{
    $rc = \Runtime\Libc\sys_signal($signo, \int_to_ptr($how));
    \__mc_sig_unblock($signo);
    return $rc !== -1;
}

/** Deliver a signal. 0 on success, -1 on failure. */
function __mc_proc_kill(int $pid, int $signo): int
{
    return \Runtime\Libc\sys_kill($pid, $signo);
}

/**
 * fork(2). 0 in the child, the child's pid in the parent, -1 on failure.
 *
 * ⚠ Forking a process with a RUNNING scheduler is a footgun: the child inherits
 * the reactor's kqueue/epoll fd, the run queue and every parked task. Fork
 * BEFORE Async\async(), which is what Async\workers() / Async\supervise() do.
 */
function __mc_proc_fork(): int
{
    return \Runtime\Libc\sys_fork();
}

/** waitpid(2). Returns the pid reaped (0 with WNOHANG when none), -1 on error. */
function __mc_proc_waitpid(int $pid, int $options, #[RefOut] int &$status): int
{
    $status = 0;
    $buf = \Runtime\Libc\calloc(1, 8);
    if ($buf === null) {
        return -1;
    }
    $r = \Runtime\Libc\sys_waitpid($pid, $buf, $options);
    $status = \peek_i32($buf, 0);
    \Runtime\Libc\free($buf);
    return $r;
}

function __mc_proc_getpid(): int
{
    return \Runtime\Libc\sys_getpid();
}

function __mc_proc_getppid(): int
{
    return \Runtime\Libc\sys_getppid();
}

/** alarm(2) — schedule SIGALRM. Returns seconds left on any previous alarm. */
function __mc_proc_alarm(int $seconds): int
{
    return \Runtime\Libc\sys_alarm($seconds);
}

/**
 * Host-divergent rlimit numbers, runtime-selected (mirrors {@see __mc_sig_const};
 * the same rule applies — no RLIMIT_* name may be written in this file). Ints
 * only, never an array. $which is the ORDER php's POSIX_RLIMIT_* constants are
 * folded in ({@see LowerPrelude}), so the two tables cannot drift apart:
 *   0 CORE  1 DATA  2 STACK  3 CPU  4 FSIZE  5 MEMLOCK  6 NPROC  7 RSS
 *   8 NOFILE  9 AS  10 = the host's own RLIM_INFINITY
 *
 * MEASURED against each host's php (which exposes the host <sys/resource.h>, the
 * way it does for FNM_*, not the invented values it uses for LOCK_*): Darwin arm64
 * vs Linux glibc — CPU/FSIZE/DATA/STACK/CORE agree at 0..4, and everything above
 * that diverges.
 */
function __mc_rlimit_const(int $which): int
{
    static $ready = 0;
    static $memlock = 0;
    static $nproc = 0;
    static $nofile = 0;
    static $as = 0;
    static $inf = 0;

    if ($ready === 0) {
        $isDarwin = \__mc_host_is_darwin();
        $memlock = $isDarwin ? 6 : 8;
        $nproc = $isDarwin ? 7 : 6;
        $nofile = $isDarwin ? 8 : 7;
        $as = $isDarwin ? 5 : 9;   // Darwin has no AS; its header aliases it to RSS
        // Darwin: (1<<63)-1. Linux glibc/musl: ~0UL, i.e. all bits set, which read
        // as a signed i64 is -1. Both are "no limit"; php reports PHP_INT_MAX.
        $inf = $isDarwin ? \PHP_INT_MAX : -1;
        $ready = 1;
    }

    if ($which === 0) { return 4; }        // CORE
    if ($which === 1) { return 2; }        // DATA
    if ($which === 2) { return 3; }        // STACK
    if ($which === 3) { return 0; }        // CPU
    if ($which === 4) { return 1; }        // FSIZE
    if ($which === 5) { return $memlock; }
    if ($which === 6) { return $nproc; }
    if ($which === 7) { return 5; }        // RSS
    if ($which === 8) { return $nofile; }
    if ($which === 9) { return $as; }
    return $inf;
}

/**
 * getrlimit(2), one value at a time — $which 0 = soft, 1 = hard. The host's
 * "infinite" is normalised to PHP_INT_MAX, which is what php's POSIX_RLIMIT_INFINITY
 * is on both hosts. Failure is PHP_INT_MIN, NOT -1: on Linux RLIM_INFINITY *is*
 * -1 in an i64, so -1 could never distinguish "no limit" from "call failed".
 */
function __mc_rlimit_get(int $resource, int $which): int
{
    $buf = \Runtime\Libc\calloc(1, 16);
    if ($buf === null) {
        return \PHP_INT_MIN;
    }
    $rc = \Runtime\Libc\sys_getrlimit($resource, $buf);
    if ($rc !== 0) {
        \Runtime\Libc\free($buf);
        return \PHP_INT_MIN;
    }
    $v = \peek_i64($buf, $which === 0 ? 0 : 8);
    \Runtime\Libc\free($buf);
    if ($v === \__mc_rlimit_const(10)) {
        return \PHP_INT_MAX;
    }
    return $v;
}

/**
 * setrlimit(2). Values go to the kernel UNTRANSLATED, which is what php does:
 * "no limit" is spelled POSIX_RLIMIT_INFINITY, and that constant carries the
 * HOST's RLIM_INFINITY — PHP_INT_MAX on Darwin, -1 on glibc. Mapping PHP_INT_MAX
 * to infinity here looked portable and was a divergence: on Linux php sets a
 * finite limit of PHP_INT_MAX and reads it back as that number, where we reported
 * 'unlimited'. 0 on success, -1 on failure.
 */
function __mc_rlimit_set(int $resource, int $soft, int $hard): int
{
    $buf = \Runtime\Libc\calloc(1, 16);
    if ($buf === null) {
        return -1;
    }
    \poke_i64($buf, 0, $soft);
    \poke_i64($buf, 8, $hard);
    $rc = \Runtime\Libc\sys_setrlimit($resource, $buf);
    \Runtime\Libc\free($buf);
    return $rc;
}
