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
            return $this === Backend::Poll || $this === Backend::Auto;
        }

        public function supportsEdgeTriggering(): bool
        {
            return false;   // the poll(2) backend is level-triggered only
        }

        /** @return Backend[] */
        public static function getAvailableBackends(): array
        {
            return [Backend::Poll];
        }
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
            $this->bits = __events_to_bits($events);
            $this->data = $data;
        }

        public function modifyEvents(array $events): void
        {
            $this->requireActive();
            $this->bits = __events_to_bits($events);
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

        public function __construct(private Backend $backend = Backend::Auto)
        {
            // Auto resolves to the platform reactor; poll(2) is the portable base.
            if ($this->backend === Backend::Auto) {
                $this->backend = Backend::Poll;
            }
            if (!$this->backend->isAvailable()) {
                throw new BackendUnavailableException("Requested backend is not available");
            }
        }

        public function getBackend(): Backend { return $this->backend; }

        public function add(Handle $handle, array $events, mixed $data = null): Watcher
        {
            $bits = __events_to_bits($events);
            $fd = $this->handleFd($handle);
            if (isset($this->watchers[$fd])) {
                throw new HandleAlreadyWatchedException("The handle is already watched by this context");
            }
            $w = new Watcher($handle, $bits, $data, $this);
            $this->watchers[$fd] = $w;
            $this->fds[$fd] = $fd;
            return $w;
        }

        /** @return Watcher[] the watchers whose fds became ready */
        public function wait(?int $timeoutSeconds = null, int $timeoutMicroseconds = 0, ?int $maxEvents = null): array
        {
            $n = \count($this->fds);
            if ($n === 0) { return []; }
            $timeout = ($timeoutSeconds === null) ? -1 : ($timeoutSeconds * 1000 + (int)($timeoutMicroseconds / 1000));

            $buf = \calloc($n, 8);        // struct pollfd[n] = {int fd; short events; short revents}
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
            return $result;
        }

        // ── internal ──
        public function __remove(Watcher $w): void
        {
            $fd = $this->handleFd($w->getHandle());
            unset($this->watchers[$fd]);
            unset($this->fds[$fd]);
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
