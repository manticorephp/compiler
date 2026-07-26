<?php
// ext/pcntl + the process half of ext/posix — the API half. The libc mechanism
// is src/Runtime/Stdlib/Pcntl.php; what lives HERE is the handler registry and
// the php-facing functions, because a registry holds CALLABLES and an array
// built inside the stdlib does not survive being parked in a static and handed
// back to user code. The prelude compiles into the user's own module, so its
// state is ordinary module state.
//
// DEMAND-GATED (Main.php): a program that calls none of these carries none of
// it. Async\ forces it on, since the scheduler dispatches signals every tick.
//
// HOW IT WORKS. A C signal handler cannot be a PHP closure, so pcntl_signal()
// does not install one: it BLOCKS the signal, which stops the default action
// from firing and leaves the signal PENDING, and remembers the callback.
// pcntl_signal_dispatch() then reaps the pending ones (sigpending + sigwait, a
// pair that exists on both hosts — unlike sigtimedwait, which Darwin lacks) and
// calls the PHP handlers.
//
// ⚠ ONE DELIBERATE DIVERGENCE FROM ZEND. php installs a real C handler that
// flags the signal, so a blocking syscall is interrupted (EINTR) even before
// dispatch. Ours keeps the signal blocked, so it never interrupts a syscall and
// arrives only at a dispatch point. The php-level contract — "the handler runs
// when pcntl_signal_dispatch() runs" — is the same, and for a cooperative
// scheduler not being interrupted mid-syscall is a feature. The practical
// consequence: pcntl_async_signals(true) cannot make delivery pre-emptive here;
// it is honoured by the async loop (which dispatches every iteration) and is a
// no-op in a plain synchronous program, which must call
// pcntl_signal_dispatch() itself.

namespace Runtime {

    /**
     * The registered signal handlers. A singleton object whose ARRAYS are
     * instance properties — the shape Async\Scheduler already proves out —
     * rather than a `static array`, which no prelude class uses.
     */
    final class Signals
    {
        private static ?Signals $inst = null;

        /** @var array<int, mixed> signal number → the php callback */
        private array $handlers = [];
        /** @var int[] the signals we have blocked, in registration order */
        private array $signos = [];

        /** Set by pcntl_async_signals(); read by the async scheduler. */
        public bool $async = false;

        public static function instance(): Signals
        {
            if (self::$inst === null) {
                self::$inst = new Signals();
            }
            return self::$inst;
        }

        public function set(int $signo, mixed $handler): void
        {
            if (!isset($this->handlers[$signo])) {
                $this->signos[] = $signo;
            }
            $this->handlers[$signo] = $handler;
        }

        public function remove(int $signo): void
        {
            if (!isset($this->handlers[$signo])) {
                return;
            }
            unset($this->handlers[$signo]);
            /** @var int[] $kept */
            $kept = [];
            foreach ($this->signos as $s) {
                if ($s !== $signo) { $kept[] = $s; }
            }
            $this->signos = $kept;
        }

        public function get(int $signo): mixed
        {
            return $this->handlers[$signo] ?? null;
        }

        /** @return int[] */
        public function signos(): array
        {
            return $this->signos;
        }

        public function any(): bool
        {
            return \count($this->signos) > 0;
        }
    }
}

namespace {

    /**
     * Install a handler for $signal. $handler is a callable, or SIG_DFL / SIG_IGN
     * to hand the signal back to the OS. A callable is NOT run asynchronously:
     * it runs at the next pcntl_signal_dispatch() (which Async\async() performs
     * every loop iteration).
     *
     * $restart_syscalls is accepted for compatibility and has no effect: the
     * signal is blocked, so it never interrupts a syscall in the first place.
     */
    function pcntl_signal(int $signal, mixed $handler, bool $restart_syscalls = true): bool
    {
        $reg = \Runtime\Signals::instance();
        if (\is_int($handler)) {
            // SIG_DFL (0) / SIG_IGN (1) — a real disposition, and we stop tracking it.
            if (!\__mc_sig_disposition($signal, $handler)) {
                return false;
            }
            $reg->remove($signal);
            return true;
        }
        if (!\__mc_sig_block($signal)) {
            return false;
        }
        $reg->set($signal, $handler);
        return true;
    }

    /** The callable installed for $signal, or null when none is. */
    function pcntl_signal_get_handler(int $signal): mixed
    {
        return \Runtime\Signals::instance()->get($signal);
    }

    /**
     * Run the handlers for every signal that has arrived since the last call.
     * The safe point: handlers are ordinary PHP, so they can allocate, throw and
     * suspend a fiber, none of which a real signal handler could do.
     */
    function pcntl_signal_dispatch(): bool
    {
        $reg = \Runtime\Signals::instance();
        if (!$reg->any()) {
            return true;
        }
        while (true) {
            $sig = \__mc_sig_take_pending($reg->signos());
            if ($sig === 0) {
                return true;
            }
            $h = $reg->get($sig);
            if ($h === null || \is_int($h)) {
                continue;
            }
            $h($sig);
        }
    }

    /**
     * Ask for signals to be delivered without an explicit dispatch call, and
     * return the PREVIOUS setting. Honoured by Async\async(), which dispatches
     * every loop iteration; in a plain synchronous program there is no safe point
     * to hook, so it records the preference and nothing more.
     */
    function pcntl_async_signals(?bool $enable = null): bool
    {
        $reg = \Runtime\Signals::instance();
        $prev = $reg->async;
        if ($enable !== null) {
            $reg->async = $enable;
        }
        return $prev;
    }

    /**
     * Block / unblock / replace the process signal mask. $old_signals receives
     * the signals that were blocked BEFORE the change.
     *
     * @param int[] $signals
     * @param int[] $old_signals
     */
    function pcntl_sigprocmask(int $mode, array $signals, #[RefOut] array &$old_signals = []): bool
    {
        // The stdlib returns the old mask as a BIT SET, not an array: an array
        // built down there does not survive the boundary.
        $bits = \__mc_sig_procmask($mode, $signals);
        /** @var int[] $old */
        $old = [];
        for ($s = 1; $s <= 64; $s = $s + 1) {
            if (($bits & (1 << ($s - 1))) !== 0) {
                $old[] = $s;
            }
        }
        $old_signals = $old;
        return true;
    }

    /** Schedule SIGALRM in $seconds (0 cancels). Returns any previous alarm's remainder. */
    function pcntl_alarm(int $seconds): int
    {
        return \__mc_proc_alarm($seconds);
    }

    /**
     * fork(2): 0 in the child, the child's pid in the parent, -1 on failure.
     *
     * ⚠ Do not fork a process that is already inside Async\async(): the child
     * inherits the reactor fd, the run queue and every parked task. Fork first —
     * Async\supervise() / Async\workers() do exactly that.
     */
    function pcntl_fork(): int
    {
        return \__mc_proc_fork();
    }

    /**
     * Wait for a child. Returns the pid reaped, 0 when WNOHANG found none, or -1.
     * @param int $status raw wait status; read it with the pcntl_w* helpers
     */
    function pcntl_waitpid(int $process_id, #[RefOut] int &$status, int $flags = 0): int
    {
        $st = 0;
        $r = \__mc_proc_waitpid($process_id, $flags, $st);
        $status = $st;
        return $r;
    }

    // The W* macros are pure bit math on the wait status and agree on both hosts
    // (Darwin's _WSTATUS(x) is x & 0177, i.e. the same low 7 bits glibc uses), so
    // they are PHP here rather than more libc bindings.

    function pcntl_wifexited(int $status): bool
    {
        return ($status & 0x7F) === 0;
    }

    function pcntl_wexitstatus(int $status): int
    {
        return ($status >> 8) & 0xFF;
    }

    function pcntl_wifsignaled(int $status): bool
    {
        $s = $status & 0x7F;
        return $s !== 0 && $s !== 0x7F;
    }

    function pcntl_wtermsig(int $status): int
    {
        return $status & 0x7F;
    }

    function pcntl_wifstopped(int $status): bool
    {
        return ($status & 0xFF) === 0x7F;
    }

    function pcntl_wstopsig(int $status): int
    {
        return ($status >> 8) & 0xFF;
    }

    /** Send $signal to $process_id (0 only checks that it exists). */
    function posix_kill(int $process_id, int $signal): bool
    {
        return \__mc_proc_kill($process_id, $signal) === 0;
    }

    function posix_getpid(): int
    {
        return \__mc_proc_getpid();
    }

    function posix_getppid(): int
    {
        return \__mc_proc_getppid();
    }
}
