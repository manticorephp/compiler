// pcntl signal handling, with NO hand-written C anywhere.
// DEMAND-GATED (Main.php).
//
// A signal handler is a C function pointer, and this compiler emits no C — so
// the usual route (install a trampoline that calls back into PHP) is closed.
// The way through is to never let a handler run in signal context at all:
//
//   1. Set the signal's disposition to SIG_IGN, so the default action (killing
//      the process) never happens and nothing is delivered asynchronously.
//   2. Observe the signal through a FILE DESCRIPTOR instead — kqueue's
//      EVFILT_SIGNAL on Darwin/BSD, signalfd(2) on Linux. Both keep reporting a
//      signal that is ignored, which is exactly the property this needs.
//   3. Run the PHP handler from ordinary code at pcntl_signal_dispatch().
//
// That is also what php does in spirit: php's own handler only sets a flag, and
// the userland callable runs later at a VM tick. There are no ticks in compiled
// code, so the dispatch point is explicit.
//
// ⚠ LIMITATION: delivery happens when pcntl_signal_dispatch() is called. A
// signal arriving while the process is blocked in read(2) is seen after that
// read returns, not during it. For a console application — the case this
// exists for — that is the right trade; a program wanting immediate delivery
// should poll the descriptor itself.

class __McSignals
{
    /** @var array<int,mixed> signo => handler callable / SIG_DFL / SIG_IGN */
    public static array $handlers = [];

    /** kqueue or signalfd descriptor, 0 when not yet opened. */
    public static int $fd = 0;

    /** Signals currently registered with the observer. */
    public static array $watched = [];
}

/** `sighandler_t signal(int signum, sighandler_t handler)` — SIG_DFL/SIG_IGN only. */
#[\Ffi\Library('c'), \Ffi\Symbol('signal')]
function __mc_sig_signal(#[\Ffi\CType('int')] int $signum, int $handler): int {}

/** `int kill(pid_t pid, int sig)` */
#[\Ffi\Library('c'), \Ffi\Symbol('kill')]
function __mc_sig_kill(#[\Ffi\CType('int')] int $pid, #[\Ffi\CType('int')] int $sig): int {}

/** `pid_t getpid(void)` */
#[\Ffi\Library('c'), \Ffi\Symbol('getpid')]
function __mc_sig_getpid(): int {}

// kqueue / kevent — Darwin and the BSDs. #[Weak] because Linux has neither.
#[\Ffi\Library('c'), \Ffi\Symbol('kqueue'), \Ffi\Weak]
function __mc_sig_kqueue(): int {}

#[\Ffi\Library('c'), \Ffi\Symbol('kevent'), \Ffi\Weak]
function __mc_sig_kevent(int $kq, \Ffi\Ptr $chg, #[\Ffi\CType('int')] int $nchg, \Ffi\Ptr $evs, #[\Ffi\CType('int')] int $nevs, \Ffi\Ptr $ts): int {}

// signalfd + sigprocmask — Linux. #[Weak] for the same reason, mirrored.
#[\Ffi\Library('c'), \Ffi\Symbol('signalfd'), \Ffi\Weak]
function __mc_sig_signalfd(#[\Ffi\CType('int')] int $fd, \Ffi\Ptr $mask, #[\Ffi\CType('int')] int $flags): int {}

#[\Ffi\Library('c'), \Ffi\Symbol('sigprocmask'), \Ffi\Weak]
function __mc_sig_sigprocmask(#[\Ffi\CType('int')] int $how, \Ffi\Ptr $set, \Ffi\Ptr $old): int {}

/** Open the per-process observer descriptor, once. */
function __mc_sig_observer(): int
{
    if (__McSignals::$fd !== 0) { return __McSignals::$fd; }
    if (\__mc_host_is_darwin()) {
        $kq = \__mc_sig_kqueue();
        if ($kq < 0) { return 0; }
        __McSignals::$fd = $kq;
        return $kq;
    }
    // Linux: signalfd(-1, &empty, SFD_NONBLOCK|SFD_CLOEXEC) creates the fd; the
    // mask is filled in per registration by __mc_sig_watch.
    $set = \Runtime\Libc\calloc(1, 128);
    $fd = \__mc_sig_signalfd(-1, $set, 2048 | 524288);
    \Runtime\Libc\free($set);
    if ($fd < 0) { return 0; }
    __McSignals::$fd = $fd;
    return $fd;
}

/** Start reporting `$signo` on the observer descriptor. */
function __mc_sig_watch(int $signo): bool
{
    $fd = \__mc_sig_observer();
    if ($fd === 0) { return false; }
    if (\__mc_host_is_darwin()) {
        // struct kevent (Darwin, 64-bit): ident u64 @0, filter i16 @8,
        // flags u16 @10, fflags u32 @12, data i64 @16, udata ptr @24.
        // EVFILT_SIGNAL = -6, EV_ADD = 1.
        $ev = \Runtime\Libc\calloc(1, 32);
        \poke_i64($ev, 0, $signo);
        \poke_i16($ev, 8, -6);
        \poke_i16($ev, 10, 1);
        $rc = \__mc_sig_kevent($fd, $ev, 1, \int_to_ptr(0), 0, \int_to_ptr(0));
        \Runtime\Libc\free($ev);
        if ($rc < 0) { return false; }
        __McSignals::$watched[$signo] = true;
        return true;
    }
    // Linux: rebuild the whole blocked set (sigset_t is a bit array, bit
    // signo-1), block it so nothing is delivered asynchronously, and re-arm
    // the same signalfd with it.
    __McSignals::$watched[$signo] = true;
    $set = \Runtime\Libc\calloc(1, 128);
    foreach (__McSignals::$watched as $s => $_) {
        $bit = $s - 1;
        $byte = \intdiv($bit, 8);
        $cur = \peek_u8($set, $byte);
        \poke_i8($set, $byte, $cur | (1 << ($bit % 8)));
    }
    \__mc_sig_sigprocmask(0, $set, \int_to_ptr(0));   // SIG_BLOCK = 0 on Linux
    \__mc_sig_signalfd($fd, $set, 0);
    \Runtime\Libc\free($set);
    return true;
}

/**
 * Install a handler for `$signo`. `$handler` is a callable, or SIG_DFL (0) /
 * SIG_IGN (1). Returns true on success, as php does.
 */
function pcntl_signal(int $signo, mixed $handler, bool $restart_syscalls = true): bool
{
    if ($handler === 0) {
        // SIG_DFL: stop watching and hand the signal back to the kernel.
        unset(__McSignals::$handlers[$signo]);
        \__mc_sig_signal($signo, 0);
        return true;
    }
    if ($handler === 1) {
        unset(__McSignals::$handlers[$signo]);
        \__mc_sig_signal($signo, 1);
        return true;
    }
    __McSignals::$handlers[$signo] = $handler;
    // SIG_IGN first: it stops the default action (which for SIGINT is death)
    // without needing a handler function, and both kqueue and signalfd still
    // report an ignored signal.
    \__mc_sig_signal($signo, 1);
    return \__mc_sig_watch($signo);
}

/** The handler installed for `$signo`: a callable, or SIG_DFL / SIG_IGN. */
function pcntl_signal_get_handler(int $signo): mixed
{
    if (isset(__McSignals::$handlers[$signo])) {
        return __McSignals::$handlers[$signo];
    }
    return 0;
}

/**
 * php's async-signals switch. Delivery here is never truly asynchronous — see
 * the file header — so this records the request and answers true; the dispatch
 * point is pcntl_signal_dispatch().
 */
function pcntl_async_signals(?bool $enable = null): bool
{
    return true;
}

/** Invoke a stored handler in any callable shape. */
function __mc_sig_call(mixed $cb, int $signo): void
{
    if (\is_array($cb)) {
        $o = $cb[0];
        $m = $cb[1];
        $o->$m($signo);
        return;
    }
    $cb($signo);
}

/**
 * Deliver every signal that arrived since the last call. Returns true, as php
 * does. Non-blocking: a zero timeout, so a program with nothing pending pays
 * one syscall.
 */
function pcntl_signal_dispatch(): bool
{
    $fd = __McSignals::$fd;
    if ($fd === 0) { return true; }
    if (\__mc_host_is_darwin()) {
        $evs = \Runtime\Libc\calloc(8, 32);
        $ts = \Runtime\Libc\calloc(1, 16);           // timespec {0,0} = poll
        $n = \__mc_sig_kevent($fd, \int_to_ptr(0), 0, $evs, 8, $ts);
        $i = 0;
        while ($i < $n) {
            $signo = \peek_i64($evs, $i * 32);
            if (isset(__McSignals::$handlers[$signo])) {
                \__mc_sig_call(__McSignals::$handlers[$signo], $signo);
            }
            $i = $i + 1;
        }
        \Runtime\Libc\free($ts);
        \Runtime\Libc\free($evs);
        return true;
    }
    // Linux: each struct signalfd_siginfo is 128 bytes, ssi_signo (u32) at 0.
    $buf = \Runtime\Libc\calloc(8, 128);
    $n = \Runtime\Libc\read($fd, $buf, 1024);
    $i = 0;
    while (($i + 1) * 128 <= $n) {
        $signo = \peek_u32($buf, $i * 128);
        if (isset(__McSignals::$handlers[$signo])) {
            \__mc_sig_call(__McSignals::$handlers[$signo], $signo);
        }
        $i = $i + 1;
    }
    \Runtime\Libc\free($buf);
    return true;
}

/** php's posix_kill: send a signal to a process. */
function posix_kill(int $process_id, int $signal): bool
{
    return \__mc_sig_kill($process_id, $signal) === 0;
}

/** php's posix_getpid. */
function posix_getpid(): int
{
    return \__mc_sig_getpid();
}

/** php's getmypid — the same number, php's other spelling. */
function getmypid(): int
{
    return \__mc_sig_getpid();
}
