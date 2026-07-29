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

    /**
     * getrlimit(2). With a resource: `[0 => soft, 1 => hard]`. Without one: every
     * limit the host has, keyed `'soft <name>'` / `'hard <name>'` — php's own
     * shape, including the STRING `'unlimited'` where the value is RLIM_INFINITY.
     *
     * The label table lives here rather than in the stdlib for the reason the file
     * header gives: an array built inside the stdlib does not survive being handed
     * back to user code. The RESOURCE numbers come from `__mc_rlimit_const()`,
     * whose $which order is the same as POSIX_RLIMIT_* is folded in.
     */
    function posix_getrlimit(?int $resource = null): array|false
    {
        if ($resource !== null) {
            $soft = \__mc_rlimit_get($resource, 0);
            if ($soft === \PHP_INT_MIN) {
                return false;
            }
            $hard = \__mc_rlimit_get($resource, 1);
            return [
                0 => $soft === \PHP_INT_MAX ? 'unlimited' : $soft,
                1 => $hard === \PHP_INT_MAX ? 'unlimited' : $hard,
            ];
        }
        // php's order and names, and only the resources both hosts have: NPROC is
        // 'maxproc', FSIZE is 'filesize', NOFILE is 'openfiles', AS is 'totalmem'.
        $names = ['core', 'data', 'stack', 'totalmem', 'rss', 'maxproc',
                  'memlock', 'cpu', 'filesize', 'openfiles'];
        $which = [0, 1, 2, 9, 7, 6, 5, 3, 4, 8];
        $out = [];
        for ($i = 0; $i < 10; $i++) {
            $res = \__mc_rlimit_const($which[$i]);
            $soft = \__mc_rlimit_get($res, 0);
            if ($soft === \PHP_INT_MIN) {
                continue;      // a resource this host does not support
            }
            $hard = \__mc_rlimit_get($res, 1);
            $out['soft ' . $names[$i]] = $soft === \PHP_INT_MAX ? 'unlimited' : $soft;
            $out['hard ' . $names[$i]] = $hard === \PHP_INT_MAX ? 'unlimited' : $hard;
        }
        return $out;
    }

    /**
     * setrlimit(2). PHP_INT_MAX (= POSIX_RLIMIT_INFINITY) means "no limit".
     * Raising a hard limit needs privilege; php returns false there rather than
     * warning, so this does too — one of the few places the
     * warning-becomes-exception rule does not apply, because there is no warning
     * to convert.
     */
    function posix_setrlimit(int $resource, int $soft_limit, int $hard_limit): bool
    {
        return \__mc_rlimit_set($resource, $soft_limit, $hard_limit) === 0;
    }
}

namespace Process {

    // The process model, which is NOT part of the concurrency model — none of
    // this runs a scheduler. It lives beside pcntl rather than in Async\ because
    // forking, reaping and restarting a worker are things you do UNDER a
    // runtime, not with one: Async\supervise() would have implied a scheduler
    // that supervision never has.
    //
    // These are a superset (php has no such functions), so there is no Zend
    // oracle for them — the pcntl_*/posix_* primitives underneath are the part
    // that difftest checks.

    /** This process's pid. */
    function pid(): int
    {
        return \posix_getpid();
    }

    /** The parent's pid. */
    function ppid(): int
    {
        return \posix_getppid();
    }

    /**
     * fork(2): 0 in the child, the child's pid in the parent, -1 on failure.
     * ⚠ Never from inside a running scheduler — see pcntl_fork().
     */
    function fork(): int
    {
        return \pcntl_fork();
    }

    /**
     * Fork into $n workers and return THIS worker's index: 0 for the original
     * process, 1..$n-1 for the forks. UNSUPERVISED — a worker that dies stays
     * dead; {@see supervise()} is the form that restarts it. A failed fork
     * degrades to fewer workers.
     */
    function workers(int $n): int
    {
        for ($i = 1; $i < $n; $i = $i + 1) {
            $pid = \pcntl_fork();
            if ($pid === 0) {
                return $i;              // child → worker $i
            }
            if ($pid < 0) {
                return 0;               // fork failed — carry on with what we have
            }
        }
        return 0;                       // the original process = worker 0
    }

    /**
     * Fork $n workers and KEEP them running: reap the dead, restart them, and
     * forward a shutdown to the whole group. The supervisor runs no scheduler of
     * its own — each child calls $worker($index), which is where Async\async()
     * goes.
     *
     *   Process\supervise(8, function (int $i) {
     *       Async\async(function () { Async\shutdownOn(SIGTERM); serve($i); });
     *   });
     *
     * Returns once every child has exited. Forking happens BEFORE any scheduler
     * exists, which is the only safe order — a fork inside a running loop hands
     * the child a copy of the reactor fd and the run queue.
     */
    function supervise(int $n, callable $worker): void
    {
        (new Supervisor())->run($n, $worker);
    }

    /** @internal the state {@see supervise()} needs across its reap loop */
    final class Supervisor
    {
        private bool $stopping = false;
        /** @var array<int, int> worker index → live pid */
        private array $pids = [];

        public function run(int $n, callable $worker): void
        {
            if ($n < 1) { $n = 1; }
            for ($i = 0; $i < $n; $i = $i + 1) {
                $this->start($i, $worker);
            }
            \pcntl_signal(\SIGTERM, function () { $this->stop(\SIGTERM); });
            \pcntl_signal(\SIGINT, function () { $this->stop(\SIGINT); });

            while (\count($this->pids) > 0) {
                \pcntl_signal_dispatch();
                $status = 0;
                $pid = \pcntl_waitpid(-1, $status, \WNOHANG);
                if ($pid > 0) {
                    $idx = $this->forget($pid);
                    if ($idx >= 0 && !$this->stopping) {
                        // Died while we are NOT shutting down: that is a crash,
                        // not an exit — put the worker back.
                        $this->start($idx, $worker);
                    }
                    continue;
                }
                \usleep(50000);
            }
        }

        private function start(int $idx, callable $worker): void
        {
            $pid = \pcntl_fork();
            if ($pid === 0) {
                $worker($idx);
                exit(0);
            }
            if ($pid > 0) {
                $this->pids[$idx] = $pid;
            }
            // pid < 0: fork failed — carry on with fewer workers.
        }

        /** Forward the shutdown to every child; the reap loop then drains. */
        private function stop(int $signo): void
        {
            $this->stopping = true;
            foreach ($this->pids as $pid) {
                \posix_kill($pid, $signo);
            }
        }

        /**
         * Drop $pid and return the worker index it held, or -1.
         *
         * ⚠ Rebuilt rather than `unset($this->pids[$idx])`: unset on a VEC
         * element is still unimplemented (it needs hole / shift semantics) and
         * SILENTLY does nothing, so the map never shrank and the reap loop above
         * spun forever waiting for a count that could not fall.
         */
        private function forget(int $pid): int
        {
            $idx = -1;
            /** @var array<int, int> $kept */
            $kept = [];
            foreach ($this->pids as $i => $p) {
                if ($p === $pid && $idx < 0) { $idx = $i; continue; }
                $kept[$i] = $p;
            }
            $this->pids = $kept;
            return $idx;
        }
    }
}
