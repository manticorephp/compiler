// Io\Poll — PHP 8.6 low-level fd-readiness multiplexer (RFC poll_api). Oracle =
// php 8.6. A thin PHP layer (compiled native) over poll(2); kqueue/epoll backends
// slot in later. DEMAND-GATED (Main.php): compiled only when a program mentions
// Io\Poll / StreamPollHandle. Namespaced classes ride braced `namespace {}` blocks
// so they stay isolated inside the global-namespace prelude blob.
//
// Attributes are fully qualified — the prelude is one concatenated blob with no
// place for a `use`.

namespace {
    #[\Ffi\Library('c'), \Ffi\Symbol('poll')]
    function __mc_iopoll_poll(\Ffi\Ptr $fds, #[\Ffi\CType('nfds_t')] int $nfds, #[\Ffi\CType('int')] int $timeout): int {}

    // ── epoll (Linux) — bound #[Ffi\Weak]: absent on macOS ⇒ extern_weak (null),
    //    never called off-Linux (Backend::Epoll only reachable when PHP_OS_FAMILY
    //    === 'Linux'). The Darwin link permits the weak-undefined via -Wl,-U.
    #[\Ffi\Library('c'), \Ffi\Symbol('epoll_create1'), \Ffi\Weak]
    function __mc_iopoll_epoll_create1(int $flags): int {}

    #[\Ffi\Library('c'), \Ffi\Symbol('epoll_ctl'), \Ffi\Weak]
    function __mc_iopoll_epoll_ctl(int $epfd, int $op, int $fd, \Ffi\Ptr $ev): int {}

    #[\Ffi\Library('c'), \Ffi\Symbol('epoll_wait'), \Ffi\Weak]
    function __mc_iopoll_epoll_wait(int $epfd, \Ffi\Ptr $events, int $maxevents, int $timeout): int {}

    // ── kqueue (Darwin/BSD) — #[Ffi\Weak] too: absent on Linux (GNU ld binds
    //    weak-undefined to 0; never called off-Darwin).
    #[\Ffi\Library('c'), \Ffi\Symbol('kqueue'), \Ffi\Weak]
    function __mc_iopoll_kqueue(): int {}

    #[\Ffi\Library('c'), \Ffi\Symbol('kevent'), \Ffi\Weak]
    function __mc_iopoll_kevent(int $kq, \Ffi\Ptr $chg, int $nchg, \Ffi\Ptr $evs, int $nevs, \Ffi\Ptr $ts): int {}

    // Portable — closes the epoll/kqueue reactor fd. Bound here (not via the
    // Libc alias) so the symbol resolves cleanly in a demand-gated program.
    #[\Ffi\Library('c'), \Ffi\Symbol('close')]
    function __mc_iopoll_close(int $fd): int {}
}

namespace Io {
    class IoException extends \Exception
    {
    }
}

namespace Io\Poll {

    // Marker interface — a pollable resource. Only internal handle types implement it.
    interface Handle
    {
    }

    // Readiness/interest events. Bit = 1 << (caseIndex) matching php's internal
    // `1 << (caseId-1)`: Read=1 Write=2 Error=4 HangUp=8 ReadHangUp=16 OneShot=32
    // EdgeTriggered=64.
    enum Event
    {
        case Read;
        case Write;
        case Error;
        case HangUp;
        case ReadHangUp;
        case OneShot;
        case EdgeTriggered;
    }

    enum Backend
    {
        case Auto;
        case Poll;
        case Epoll;
        case Kqueue;
        case EventPorts;
        case WSAPoll;

        public function isAvailable(): bool
        {
            if ($this === Backend::Poll || $this === Backend::Auto) { return true; }
            if ($this === Backend::Epoll) { return PHP_OS_FAMILY === 'Linux'; }
            if ($this === Backend::Kqueue) {
                return PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD';
            }
            return false;   // EventPorts (Solaris) / WSAPoll (Windows): unsupported
        }

        public function supportsEdgeTriggering(): bool
        {
            // poll(2) is level-triggered only; epoll (EPOLLET) and kqueue (EV_CLEAR)
            // support edge-triggering.
            return $this === Backend::Epoll || $this === Backend::Kqueue;
        }

        /** @return Backend[] the concrete (non-Auto) backends available on this host */
        public static function getAvailableBackends(): array
        {
            // php iterates {Poll,Epoll,Kqueue,EventPorts,WSAPoll} keeping the
            // available ones — Poll is always first.
            $out = [Backend::Poll];
            if (Backend::Epoll->isAvailable()) { $out[] = Backend::Epoll; }
            if (Backend::Kqueue->isAvailable()) { $out[] = Backend::Kqueue; }
            return $out;
        }
    }

    /** Backend::Auto → the platform reactor (Linux epoll / Darwin kqueue / else poll). */
    function __auto_backend(): Backend
    {
        if (PHP_OS_FAMILY === 'Linux') { return Backend::Epoll; }
        if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') { return Backend::Kqueue; }
        return Backend::Poll;
    }

    /** Internal event bits → epoll interest flags (EPOLLIN=1|EPOLLOUT=4). */
    function __bits_to_epoll(int $bits): int
    {
        $m = 0;
        if (($bits & 1) !== 0) { $m = $m | 1; }             // Read  -> EPOLLIN
        if (($bits & 2) !== 0) { $m = $m | 4; }             // Write -> EPOLLOUT
        if (($bits & 32) !== 0) { $m = $m | 0x40000000; }   // OneShot -> EPOLLONESHOT
        if (($bits & 64) !== 0) { $m = $m | 0x80000000; }   // EdgeTriggered -> EPOLLET
        return $m;
    }

    /** epoll revents → internal event bits. */
    function __epoll_to_bits(int $ev): int
    {
        $m = 0;
        if (($ev & 1) !== 0) { $m = $m | 1; }     // EPOLLIN  -> Read
        if (($ev & 4) !== 0) { $m = $m | 2; }     // EPOLLOUT -> Write
        if (($ev & 8) !== 0) { $m = $m | 4; }     // EPOLLERR -> Error
        if (($ev & 16) !== 0) { $m = $m | 8; }    // EPOLLHUP -> HangUp
        return $m;
    }

    // ── event <-> poll(2) flag conversion ──────────────────────────────────
    // Requestable poll flags: POLLIN=1, POLLPRI=2, POLLOUT=4. Output-only:
    // POLLERR=8, POLLHUP=16, POLLNVAL=32 (always reported, never requested).
    /** @param Event[] $events */
    function __events_to_poll(array $events): int
    {
        $m = 0;
        foreach ($events as $e) {
            if ($e === Event::Read) { $m = $m | 1; }
            elseif ($e === Event::Write) { $m = $m | 4; }
            // Error/HangUp/ReadHangUp are output-only under poll(2) — not requested.
        }
        return $m;
    }

    /** Internal event bitmask for a set of Event cases (php's `1<<(id-1)`). */
    function __events_to_bits(array $events): int
    {
        $m = 0;
        foreach ($events as $e) {
            if ($e === Event::Read) { $m = $m | 1; }
            elseif ($e === Event::Write) { $m = $m | 2; }
            elseif ($e === Event::Error) { $m = $m | 4; }
            elseif ($e === Event::HangUp) { $m = $m | 8; }
            elseif ($e === Event::ReadHangUp) { $m = $m | 16; }
            elseif ($e === Event::OneShot) { $m = $m | 32; }
            elseif ($e === Event::EdgeTriggered) { $m = $m | 64; }
        }
        return $m;
    }

    /** Map poll(2) revents → the internal Event bitmask. */
    function __revents_to_bits(int $revents): int
    {
        $m = 0;
        if (($revents & 1) !== 0) { $m = $m | 1; }    // POLLIN  -> Read
        if (($revents & 4) !== 0) { $m = $m | 2; }    // POLLOUT -> Write
        if (($revents & 8) !== 0) { $m = $m | 4; }    // POLLERR -> Error
        if (($revents & 16) !== 0) { $m = $m | 8; }   // POLLHUP -> HangUp
        return $m;
    }

    /** @return Event[] the Event cases set in an internal bitmask. */
    function __bits_to_events(int $bits): array
    {
        $out = [];
        if (($bits & 1) !== 0) { $out[] = Event::Read; }
        if (($bits & 2) !== 0) { $out[] = Event::Write; }
        if (($bits & 4) !== 0) { $out[] = Event::Error; }
        if (($bits & 8) !== 0) { $out[] = Event::HangUp; }
        if (($bits & 16) !== 0) { $out[] = Event::ReadHangUp; }
        if (($bits & 32) !== 0) { $out[] = Event::OneShot; }
        if (($bits & 64) !== 0) { $out[] = Event::EdgeTriggered; }
        return $out;
    }

    final class Watcher
    {
        private int $bits;         // watched events (internal bitmask)
        private int $triggered = 0;
        private bool $active = true;

        // php exposes a private ctor; ours is created only by Context::add via
        // __create (a plain ctor is the pragmatic userland equivalent).
        public function __construct(
            private Handle $handle,
            int $bits,
            private mixed $data,
            private ?Context $context,
        ) {
            $this->bits = $bits;
        }

        public function getHandle(): Handle { return $this->handle; }
        /** @return Event[] */
        public function getWatchedEvents(): array { return __bits_to_events($this->bits); }
        /** @return Event[] */
        public function getTriggeredEvents(): array { return __bits_to_events($this->triggered); }
        public function getData(): mixed { return $this->data; }

        public function hasTriggered(Event $event): bool
        {
            return ($this->triggered & __events_to_bits([$event])) !== 0;
        }

        public function isActive(): bool { return $this->active; }

        public function modify(array $events, mixed $data = null): void
        {
            $this->requireActive();
            $old = $this->bits;
            $this->bits = __events_to_bits($events);
            $this->data = $data;
            $this->context->__resync($this, $old, $this->bits);
        }

        public function modifyEvents(array $events): void
        {
            $this->requireActive();
            $old = $this->bits;
            $this->bits = __events_to_bits($events);
            $this->context->__resync($this, $old, $this->bits);
        }

        public function modifyData(mixed $data): void
        {
            $this->requireActive();
            $this->data = $data;
        }

        public function remove(): void
        {
            $this->requireActive();
            $this->context->__remove($this);
            $this->active = false;
            $this->context = null;
        }

        // ── internal (Context-facing) ──
        public function __bits(): int { return $this->bits; }
        public function __setTriggered(int $bits): void { $this->triggered = $bits; }

        private function requireActive(): void
        {
            if (!$this->active) {
                throw new InactiveWatcherException("The watcher is no longer active");
            }
        }
    }

    final class Context
    {
        /** @var Watcher[] fd → watcher */
        private array $watchers = [];
        /** @var int[] fd → fd (to rebuild the poll set; keeps insertion set) */
        private array $fds = [];
        // epoll/kqueue reactor fd; -1 for the Poll backend (rebuilds pollfd each wait).
        private int $reactorFd = -1;
        // struct epoll_event layout is arch-dependent: aarch64 is naturally
        // aligned (16B, data@8); x86_64 is __attribute__((packed)) (12B, data@4).
        private int $epStride = 16;
        private int $epDataOff = 8;

        public function __construct(private Backend $backend = Backend::Auto)
        {
            if ($this->backend === Backend::Auto) {
                $this->backend = __auto_backend();
            }
            if (!$this->backend->isAvailable()) {
                throw new BackendUnavailableException("Requested backend is not available");
            }
            if ($this->backend === Backend::Epoll) {
                if (\php_uname('m') === 'x86_64') {
                    $this->epStride = 12;
                    $this->epDataOff = 4;
                }
                $this->reactorFd = \__mc_iopoll_epoll_create1(0);
                if ($this->reactorFd < 0) {
                    throw new FailedContextInitializationException("epoll_create1() failed");
                }
            } elseif ($this->backend === Backend::Kqueue) {
                $this->reactorFd = \__mc_iopoll_kqueue();
                if ($this->reactorFd < 0) {
                    throw new FailedContextInitializationException("kqueue() failed");
                }
            }
        }

        public function __destruct()
        {
            if ($this->reactorFd >= 0) {
                \__mc_iopoll_close($this->reactorFd);
                $this->reactorFd = -1;
            }
        }

        public function getBackend(): Backend { return $this->backend; }

        public function add(Handle $handle, array $events, mixed $data = null): Watcher
        {
            if (\count($events) === 0) {
                throw new \TypeError("Io\\Poll\\Context::add(): Argument #2 (\$events) must be array of Event enums");
            }
            $bits = __events_to_bits($events);
            $fd = $this->handleFd($handle);
            if (isset($this->watchers[$fd])) {
                throw new HandleAlreadyWatchedException("The handle is already watched by this context");
            }
            $w = new Watcher($handle, $bits, $data, $this);
            $this->watchers[$fd] = $w;
            $this->fds[$fd] = $fd;
            if ($this->backend === Backend::Epoll) {
                $this->__epollCtl(1, $fd, $bits);          // EPOLL_CTL_ADD
            } elseif ($this->backend === Backend::Kqueue) {
                $this->__kqueueSet($fd, 0, $bits);
            }
            return $w;
        }

        /** @return Watcher[] the watchers whose fds became ready */
        public function wait(?int $timeoutSeconds = null, int $timeoutMicroseconds = 0, ?int $maxEvents = null): array
        {
            if (\count($this->fds) === 0) { return []; }
            if ($this->backend === Backend::Epoll) {
                return $this->__waitEpoll($timeoutSeconds, $timeoutMicroseconds, $maxEvents);
            }
            if ($this->backend === Backend::Kqueue) {
                return $this->__waitKqueue($timeoutSeconds, $timeoutMicroseconds, $maxEvents);
            }
            return $this->__waitPoll($timeoutSeconds, $timeoutMicroseconds, $maxEvents);
        }

        // ── Backend::Poll — rebuild a pollfd[] each wait() ──
        private function __waitPoll(?int $timeoutSeconds, int $timeoutMicroseconds, ?int $maxEvents): array
        {
            $n = \count($this->fds);
            $timeout = ($timeoutSeconds === null) ? -1 : ($timeoutSeconds * 1000 + (int)($timeoutMicroseconds / 1000));

            $buf = \Runtime\Libc\calloc($n, 8);   // struct pollfd[n] = {int fd; short events; short revents}
            $i = 0;
            foreach ($this->fds as $fd) {
                $w = $this->watchers[$fd];
                $off = $i * 8;
                \poke_i32($buf, $off, $fd);
                \poke_i16($buf, $off + 4, __poll_req($w->__bits()));
                $i = $i + 1;
            }
            $rc = \__mc_iopoll_poll($buf, $n, $timeout);
            if ($rc < 0) {
                \Runtime\Libc\free($buf);
                throw new FailedPollWaitException("poll() failed");
            }
            $result = [];
            $i = 0;
            foreach ($this->fds as $fd) {
                $off = $i * 8;
                $revents = \peek_i16($buf, $off + 6) & 0xFFFF;
                $w = $this->watchers[$fd];
                if ($revents !== 0) {
                    $w->__setTriggered(__revents_to_bits($revents));
                    $result[] = $w;
                } else {
                    $w->__setTriggered(0);
                }
                $i = $i + 1;
            }
            \Runtime\Libc\free($buf);
            return $result;
        }

        // ── Backend::Epoll (Linux) ──
        // ⚠ struct epoll_event assumed 16B (events@0 u32, data@8 u64) — the
        // aarch64/naturally-aligned layout. x86_64 packs to 12B (data@4); TODO
        // when an x86_64 leg exists. Docker + host are arm64.
        private function __waitEpoll(?int $timeoutSeconds, int $timeoutMicroseconds, ?int $maxEvents): array
        {
            $n = \count($this->fds);
            $max = ($maxEvents === null) ? 64 : $maxEvents;
            if ($max > $n) { $max = $n; }              // at most n fds can be ready
            $timeout = ($timeoutSeconds === null) ? -1 : ($timeoutSeconds * 1000 + (int)($timeoutMicroseconds / 1000));

            $buf = \Runtime\Libc\calloc($max, $this->epStride);
            $rc = \__mc_iopoll_epoll_wait($this->reactorFd, $buf, $max, $timeout);
            if ($rc < 0) {
                \Runtime\Libc\free($buf);
                throw new FailedPollWaitException("epoll_wait() failed");
            }
            foreach ($this->fds as $fd) { $this->watchers[$fd]->__setTriggered(0); }
            $result = [];
            $i = 0;
            while ($i < $rc) {
                $off = $i * $this->epStride;
                $evbits = \peek_u32($buf, $off);
                $fd = \peek_i64($buf, $off + $this->epDataOff);
                if (isset($this->watchers[$fd])) {
                    $w = $this->watchers[$fd];
                    $w->__setTriggered(__epoll_to_bits($evbits));
                    $result[] = $w;
                }
                $i = $i + 1;
            }
            \Runtime\Libc\free($buf);
            return $result;
        }

        // ── Backend::Kqueue (Darwin/BSD) — parity-deferred (no macOS 8.6 oracle) ──
        // struct kevent (64-bit): ident@0 u64, filter@8 i16, flags@10 u16,
        // fflags@12 u32, data@16 i64, udata@24 ptr — 32B.
        private function __waitKqueue(?int $timeoutSeconds, int $timeoutMicroseconds, ?int $maxEvents): array
        {
            $n = \count($this->fds);
            $cap = $n * 2;                             // read+write are separate kevents
            $max = ($maxEvents === null) ? 64 : $maxEvents;
            if ($max > $cap) { $max = $cap; }
            if ($max < 1) { $max = 1; }

            $buf = \Runtime\Libc\calloc($max, 32);
            if ($timeoutSeconds === null) {
                $tp = \int_to_ptr(0);                  // NULL timespec = block forever
            } else {
                $tp = \Runtime\Libc\calloc(1, 16);
                \poke_i64($tp, 0, $timeoutSeconds);
                \poke_i64($tp, 8, $timeoutMicroseconds * 1000);
            }
            $rc = \__mc_iopoll_kevent($this->reactorFd, \int_to_ptr(0), 0, $buf, $max, $tp);
            if ($timeoutSeconds !== null) { \Runtime\Libc\free($tp); }
            if ($rc < 0) {
                \Runtime\Libc\free($buf);
                throw new FailedPollWaitException("kevent() failed");
            }
            foreach ($this->fds as $fd) { $this->watchers[$fd]->__setTriggered(0); }
            // A fd may fire on both filters — accumulate bits per fd.
            $acc = [];
            $i = 0;
            while ($i < $rc) {
                $off = $i * 32;
                $fd = \peek_i64($buf, $off);
                $filter = \peek_i16($buf, $off + 8);
                $flags = \peek_u16($buf, $off + 10);
                $b = 0;
                if ($filter === -1) { $b = $b | 1; }          // EVFILT_READ  -> Read
                elseif ($filter === -2) { $b = $b | 2; }      // EVFILT_WRITE -> Write
                if (($flags & 0x8000) !== 0) { $b = $b | 8; } // EV_EOF   -> HangUp
                if (($flags & 0x4000) !== 0) { $b = $b | 4; } // EV_ERROR -> Error
                $acc[$fd] = ($acc[$fd] ?? 0) | $b;
                $i = $i + 1;
            }
            \Runtime\Libc\free($buf);
            $result = [];
            foreach ($acc as $fd => $b) {
                if (isset($this->watchers[$fd])) {
                    $w = $this->watchers[$fd];
                    $w->__setTriggered($b);
                    $result[] = $w;
                }
            }
            return $result;
        }

        // ── internal ──
        public function __remove(Watcher $w): void
        {
            $fd = $this->handleFd($w->getHandle());
            if ($this->backend === Backend::Epoll) {
                $this->__epollCtl(2, $fd, 0);              // EPOLL_CTL_DEL
            } elseif ($this->backend === Backend::Kqueue) {
                $this->__kqueueSet($fd, $w->__bits(), 0);
            }
            unset($this->watchers[$fd]);
            unset($this->fds[$fd]);
        }

        // Reactor re-registration after a Watcher::modify(Events).
        public function __resync(Watcher $w, int $oldBits, int $newBits): void
        {
            if ($this->backend === Backend::Epoll) {
                $this->__epollCtl(3, $this->handleFd($w->getHandle()), $newBits);   // EPOLL_CTL_MOD
            } elseif ($this->backend === Backend::Kqueue) {
                $this->__kqueueSet($this->handleFd($w->getHandle()), $oldBits, $newBits);
            }
            // Poll rebuilds the set each wait() — nothing to do.
        }

        private function __epollCtl(int $op, int $fd, int $bits): void
        {
            $ev = \Runtime\Libc\calloc(1, $this->epStride);
            \poke_i32($ev, 0, __bits_to_epoll($bits));
            \poke_i64($ev, $this->epDataOff, $fd);          // data.fd (in the u64 union slot)
            $rc = \__mc_iopoll_epoll_ctl($this->reactorFd, $op, $fd, $ev);
            \Runtime\Libc\free($ev);
            if ($rc < 0) {
                throw new FailedHandleAddException("epoll_ctl() failed");
            }
        }

        // Register/unregister the read/write kqueue filters for a fd's bit delta.
        private function __kqueueSet(int $fd, int $oldBits, int $newBits): void
        {
            $chg = \Runtime\Libc\calloc(2, 32);
            $k = 0;
            // EV_ADD=1, plus EV_CLEAR=0x20 (edge-triggered) / EV_ONESHOT=0x10.
            $addF = 1;
            if (($newBits & 64) !== 0) { $addF = $addF | 0x20; }   // EdgeTriggered -> EV_CLEAR
            if (($newBits & 32) !== 0) { $addF = $addF | 0x10; }   // OneShot -> EV_ONESHOT
            $oldR = ($oldBits & 1) !== 0; $newR = ($newBits & 1) !== 0;
            if ($newR && !$oldR) { $k = $this->__kevPut($chg, $k, $fd, -1, $addF); }   // EVFILT_READ, EV_ADD
            elseif (!$newR && $oldR) { $k = $this->__kevPut($chg, $k, $fd, -1, 2); }   // EV_DELETE
            $oldW = ($oldBits & 2) !== 0; $newW = ($newBits & 2) !== 0;
            if ($newW && !$oldW) { $k = $this->__kevPut($chg, $k, $fd, -2, $addF); }   // EVFILT_WRITE, EV_ADD
            elseif (!$newW && $oldW) { $k = $this->__kevPut($chg, $k, $fd, -2, 2); }
            if ($k > 0) {
                $rc = \__mc_iopoll_kevent($this->reactorFd, $chg, $k, \int_to_ptr(0), 0, \int_to_ptr(0));
                \Runtime\Libc\free($chg);
                if ($rc < 0) {
                    throw new FailedHandleAddException("kevent() register failed");
                }
            } else {
                \Runtime\Libc\free($chg);
            }
        }

        private function __kevPut(\Ffi\Ptr $buf, int $i, int $ident, int $filter, int $flags): int
        {
            $off = $i * 32;
            \poke_i64($buf, $off, $ident);       // ident  @0
            \poke_i16($buf, $off + 8, $filter);  // filter @8  (int16)
            \poke_i16($buf, $off + 10, $flags);  // flags  @10 (uint16)
            \poke_i32($buf, $off + 12, 0);       // fflags @12
            \poke_i64($buf, $off + 16, 0);       // data   @16
            \poke_i64($buf, $off + 24, 0);       // udata  @24
            return $i + 1;
        }

        private function handleFd(Handle $handle): int
        {
            if (!($handle instanceof \StreamPollHandle)) {
                throw new InvalidHandleException("Unsupported handle type");
            }
            return $handle->__fd();
        }
    }

    /** Internal event bits → the requestable poll(2) flags (POLLIN=1|POLLOUT=4). */
    function __poll_req(int $bits): int
    {
        $m = 0;
        if (($bits & 1) !== 0) { $m = $m | 1; }   // Read  -> POLLIN
        if (($bits & 2) !== 0) { $m = $m | 4; }   // Write -> POLLOUT
        return $m;
    }

    class PollException extends \Io\IoException
    {
    }

    abstract class FailedPollOperationException extends PollException
    {
        public const ERROR_NONE = 0;
        public const ERROR_SYSTEM = 1;
        public const ERROR_NOMEM = 2;
        public const ERROR_INVALID = 3;
        public const ERROR_EXISTS = 4;
        public const ERROR_NOTFOUND = 5;
        public const ERROR_TIMEOUT = 6;
        public const ERROR_INTERRUPTED = 7;
        public const ERROR_PERMISSION = 8;
        public const ERROR_TOOBIG = 9;
        public const ERROR_AGAIN = 10;
        public const ERROR_NOSUPPORT = 11;
    }

    final class FailedContextInitializationException extends FailedPollOperationException {}
    final class FailedHandleAddException extends FailedPollOperationException {}
    final class FailedWatcherModificationException extends FailedPollOperationException {}
    final class FailedPollWaitException extends FailedPollOperationException {}

    final class BackendUnavailableException extends PollException {}
    final class InactiveWatcherException extends PollException {}
    final class HandleAlreadyWatchedException extends PollException {}
    final class InvalidHandleException extends PollException {}
}

namespace {
    // A pollable wrapper around a stream resource. Global namespace, per the RFC.
    final class StreamPollHandle implements \Io\Poll\Handle
    {
        public function __construct(private \Resource $stream) {}

        public function getStream(): \Resource { return $this->stream; }

        public function isValid(): bool { return $this->stream->addr >= 0; }

        // ── internal: the underlying fd ──
        public function __fd(): int { return $this->stream->addr; }
    }
}
