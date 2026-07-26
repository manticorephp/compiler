<?php
// Async\ — Go-style green threads with structured concurrency. Cheap stackful
// fibers multiplexed by a cooperative scheduler over an Io\Poll reactor
// (kqueue/epoll) and a timer heap. Blocking-LOOKING I/O that transparently
// suspends: the netpoller seam (\Runtime\AsyncHook) lets the ordinary stdlib
// stream layer — fread/fwrite/stream_socket_accept/fclose, and everything above
// them — park the calling fiber instead of the process.
//
// DEMAND-GATED (Main.php): a program that never names Async\ carries none of it.
// Pulls in \Fiber and Io\Poll, so the gate forces those two on as well.
//
// STRUCTURED, by construction:
//   - every task is owned by a scope (TaskGroup); async() opens an implicit ROOT
//     scope, so a top-level spawn() is still owned — no fire-and-forget;
//   - a scope does not close until every child has settled;
//   - the first child failure CANCELS its siblings for real (Fiber::throw at
//     their suspend point) and propagates out of the scope;
//   - a failure nobody claimed is escalated to the owning scope, never dropped;
//   - if the loop runs out of work while tasks are still parked, that is a
//     DEADLOCK and it is reported, not silently exited (Go's "all goroutines
//     are asleep").
//
// ⚠ NOT async: regular-FILE I/O. O_NONBLOCK is a no-op for regular files on both
// Linux and macOS, and there is no thread pool / aio / io_uring here, so
// file_get_contents('/path'), fopen()+fread() on a KIND_FILE handle and friends
// block the whole loop. Network I/O (DNS, connect, TLS handshake, read, write,
// accept) is fully async. Off-loading file I/O is a separate epic.
//
// ⚠ NO `static` LOCALS anywhere in this file: a static local is backed by a
// module global (Compile\Mir\LocalSlots), i.e. one cell shared by every fiber,
// so it corrupts under concurrency. Per-task state lives on Task.

namespace Async {

    // ── raw fd-level I/O ────────────────────────────────────────────────────
    // We own the reactor, so the fast path must NOT go through stream stdio —
    // that does its own internal poll(2) per call and blocks the single thread.

    #[\Ffi\Library('c'), \Ffi\Symbol('recv')]
    function sys_recv(int $fd, \Ffi\Ptr $buf, int $len, int $flags): int {}

    #[\Ffi\Library('c'), \Ffi\Symbol('send')]
    function sys_send(int $fd, string $buf, int $len, int $flags): int {}

    #[\Ffi\Library('c'), \Ffi\Symbol('fork')]
    function sys_fork(): int {}

    #[\Ffi\Library('c'), \Ffi\Symbol('getpid')]
    function sys_getpid(): int {}

    /** Thrown into a task that its scope cancelled. Never escalated as a failure. */
    final class CancelledException extends \RuntimeException {}

    /** A deadline elapsed before the work finished. Raised by {@see timeout()}. */
    final class TimeoutException extends \RuntimeException {}

    /** The loop ran out of work while tasks were still parked — nothing can wake them. */
    final class DeadlockException extends \RuntimeException {}

    /**
     * The READ half of cancellation — a handle you can pass anywhere (into a
     * helper, a library, a long loop) without also handing over the power to
     * cancel. The WRITE half is the scope itself: `TaskGroup::cancel()`. Keeping
     * them as one object with two views means there is no second cancellation
     * mechanism to keep in sync with the scope tree — a token is just a view of a
     * `TaskGroup`, and cancellation walks the same parent chain the scheduler
     * already uses.
     *
     *   Async\group(function (TaskGroup $g) {
     *       $tok = $g->token();
     *       $g->spawn(function () use ($tok) {
     *           while (!$tok->isCancelled()) { step(); }
     *       });
     *       $g->cancel();                 // the write half
     *   });
     *
     * Inside a task, `Context::token()` gives the token of the scope it is in.
     */
    final class CancellationToken
    {
        public function __construct(private TaskGroup $scope) {}

        /** True once this scope, or any ancestor, was cancelled. */
        public function isCancelled(): bool
        {
            $g = $this->scope;
            while ($g !== null) {
                if ($g->cancelled) { return true; }
                $g = $g->parent;
            }
            return false;
        }

        /**
         * Cancellation checkpoint. A task that suspends gets a
         * CancelledException delivered automatically; this is for a CPU-bound
         * loop that never reaches a suspend point.
         */
        public function throwIfCancelled(): void
        {
            if ($this->isCancelled()) {
                throw new CancelledException('task cancelled');
            }
        }

        /** The earliest deadline on this scope or an ancestor, as a unix time. */
        public function deadline(): ?float
        {
            return $this->scope->deadlineAt();
        }

        /** Seconds left before the deadline (never negative); null when unbounded. */
        public function remaining(): ?float
        {
            $d = $this->deadline();
            if ($d === null) { return null; }
            $left = $d - \microtime(true);
            return $left > 0.0 ? $left : 0.0;
        }

        /**
         * Run $fn when this scope is cancelled — the hook for releasing a
         * resource the scheduler knows nothing about (a handle, a lock, a
         * subprocess). Fires once, synchronously, inside `cancel()`.
         */
        public function onCancel(callable $fn): void
        {
            if ($this->isCancelled()) {
                $fn();
                return;
            }
            $this->scope->cancelHandlers[] = $fn;
        }
    }

    /**
     * Several sibling errors as one throwable. Raised by {@see awaitAny()} when
     * EVERY input failed. `previous` is the first error so a plain getPrevious()
     * still gives a representative cause; {@see errors()} is the full map keyed
     * by the failing task's INPUT position.
     */
    final class AggregateError extends \RuntimeException
    {
        /** @param array<int, \Throwable> $errors keyed by input position */
        public function __construct(string $message, private array $errors)
        {
            $first = null;
            foreach ($errors as $e) { $first = $e; break; }
            parent::__construct($message, 0, $first);
        }

        /** @return array<int, \Throwable> */
        public function errors(): array
        {
            return $this->errors;
        }
    }

    /**
     * A running unit of async work — a fiber plus its settled state. Created by
     * {@see spawn()} / {@see TaskGroup::spawn()}, never constructed directly.
     */
    final class Task
    {
        public const PENDING = 0;
        public const DONE = 1;
        public const FAILED = 2;

        public int $state = self::PENDING;
        public mixed $result = null;
        public ?\Throwable $error = null;

        /** @var Task[] tasks awaiting THIS one — woken when it settles */
        public array $waiters = [];

        /**
         * Someone has taken responsibility for this task's outcome (an await, a
         * join). An UNCLAIMED failure is escalated to the owning scope so it can
         * never be lost. Set at REGISTRATION time, before the awaiter parks —
         * "has a waiter right now" is not a usable proxy (a bulk await is parked
         * on one task while the others settle).
         */
        public bool $claimed = false;

        /** Already sitting in the run queue — keeps wake() idempotent. */
        public bool $queued = false;

        /** Delivered into the fiber via Fiber::throw() at its suspend point. */
        public ?\Throwable $pendingThrow = null;

        /** The fd this task is parked on, or -1. Owned by the scheduler. */
        public int $ioFd = -1;
        /** True when the park is for writability (else readability). */
        public bool $ioWrite = false;

        /** Armed while parked on a timer; cleared on wake/cancel (lazy heap delete). */
        public bool $timerActive = false;

        /** A value handed to this task while it was parked on a {@see Channel}. */
        public mixed $chanValue = null;
        /** False when the delivering channel was closed (recv/select report !ok). */
        public bool $chanOk = true;
        /** True while parked across several channels in a {@see select()}. */
        public bool $selecting = false;
        /** Set once ONE channel commits to delivering to this select-waiter. */
        public bool $selectClaimed = false;
        /** The channel that actually delivered — identifies the winning case. */
        public ?Channel $chanReady = null;

        /** Position in owner->children, or -1 once pruned (O(1) removal). */
        public int $idx = -1;

        /**
         * PER-TASK recv scratch buffer. Must not be shared: a read holds the
         * pointer across a suspend, so one global buffer that grows (free +
         * calloc) hands every parked reader a dangling pointer.
         */
        public ?\Ffi\Ptr $rbuf = null;
        public int $rbufLen = 0;

        /**
         * $owner is the scope that OWNS this task (settle/prune/escalate target)
         * and never changes. $scope is the innermost scope currently open INSIDE
         * this task — what spawn() attaches to. They differ while the task is
         * inside a nested TaskGroup::run(). Keeping the scope here, per task, and
         * not on a scheduler-global stack is what makes nesting sound under
         * interleaving: a fiber can only ever read its OWN open scope.
         */
        public function __construct(
            public \Fiber $fiber,
            public TaskGroup $owner,
            public TaskGroup $scope,
        ) {}

        public function isDone(): bool { return $this->state !== self::PENDING; }

        /** Register $w as a waiter, once. Small lists — a scan beats a map here. */
        public function addWaiter(Task $w): void
        {
            foreach ($this->waiters as $x) {
                if ($x === $w) { return; }
            }
            $this->waiters[] = $w;
        }

        /**
         * Suspend the calling task until this one settles, then return its value
         * (or rethrow its error). Loops: a wake is a HINT, not a promise — the
         * state is what decides.
         */
        public function await(): mixed
        {
            $sched = Scheduler::instance();
            $this->claimed = true;
            while ($this->state === self::PENDING) {
                $this->addWaiter($sched->current());
                $sched->suspendCurrent();
            }
            if ($this->state === self::FAILED) {
                throw $this->error;
            }
            return $this->result;
        }

        /**
         * Await with a deadline. On expiry the task is CANCELLED and joined (it
         * does not keep running unobserved), then {@see TimeoutException} is
         * thrown — a timeout that leaves the work running is not a timeout.
         */
        public function awaitWithin(float $seconds): mixed
        {
            $sched = Scheduler::instance();
            $this->claimed = true;
            if (!$sched->awaitDeadline($this, \microtime(true) + $seconds)) {
                $sched->cancelTask($this);
                while ($this->state === self::PENDING) {
                    $this->addWaiter($sched->current());
                    $sched->suspendCurrent();
                }
                throw new TimeoutException('await timed out after ' . (string)$seconds . 's');
            }
            if ($this->state === self::FAILED) {
                throw $this->error;
            }
            return $this->result;
        }
    }

    /**
     * A structured-concurrency scope (a "nursery"). Every spawn() attaches to the
     * innermost scope OPEN IN THE CALLING TASK; the scope does not close until all
     * its children have settled, so a task can never outlive it. The first child
     * failure cancels the siblings and propagates out of the group.
     *
     *   TaskGroup::run(function (TaskGroup $g) {
     *       $g->spawn(fn() => work(1));
     *       $g->spawn(fn() => work(2));
     *   }); // returns only once both children are done
     */
    final class TaskGroup
    {
        /** @var Task[] live children (settled ones are pruned) */
        public array $children = [];
        public bool $cancelled = false;
        public ?\Throwable $failure = null;

        /** Unix time this scope must finish by, or null. Set by {@see timeout()}. */
        public ?float $deadline = null;

        /** @var callable[] run once when this scope is cancelled */
        public array $cancelHandlers = [];

        public function __construct(public ?TaskGroup $parent = null) {}

        /** The read-only cancellation handle for this scope. */
        public function token(): CancellationToken
        {
            return new CancellationToken($this);
        }

        /**
         * The earliest deadline on this scope or an ancestor. A nested timeout
         * can only ever TIGHTEN the enclosing one — a 30s inner scope inside a
         * 2s outer one still dies at 2s.
         */
        public function deadlineAt(): ?float
        {
            $best = null;
            $g = $this;
            while ($g !== null) {
                if ($g->deadline !== null && ($best === null || $g->deadline < $best)) {
                    $best = $g->deadline;
                }
                $g = $g->parent;
            }
            return $best;
        }

        /** Open a scope, run $body($group), then join every child before returning. */
        public static function run(callable $body): mixed
        {
            $sched = Scheduler::instance();
            $cur = $sched->current();
            $group = new TaskGroup($cur->scope);
            $cur->scope = $group;
            $result = null;
            try {
                $result = $body($group);
            } catch (\Throwable $e) {
                // The body itself blew up — the children it already spawned are
                // NOT orphaned: record, cancel, and still join below.
                $group->fail($e);
            }
            $group->joinAll();
            $cur->scope = $group->parent;
            if ($group->failure !== null) {
                throw $group->failure;
            }
            return $result;
        }

        /** Spawn a child task into this group. Returns a handle to await. */
        public function spawn(callable $fn, mixed ...$args): Task
        {
            $task = Scheduler::instance()->newTask($fn, $args, $this);
            $task->idx = \count($this->children);
            $this->children[] = $task;
            if ($this->cancelled) {
                // Spawning into an already-cancelled scope: the child starts and
                // dies at its first suspend point rather than running to term.
                Scheduler::instance()->cancelTask($task);
            }
            return $task;
        }

        /**
         * Wait for every child to settle. Children are pruned as they settle, so
         * this drains until empty rather than walking a snapshot. A child failure
         * (other than the cancellation we ourselves caused) becomes the group's.
         */
        public function joinAll(): void
        {
            $sched = Scheduler::instance();
            while (\count($this->children) > 0) {
                $child = $this->children[0];
                $child->claimed = true;
                while ($child->state === Task::PENDING) {
                    $child->addWaiter($sched->current());
                    $sched->suspendCurrent();
                }
                if ($child->state === Task::FAILED && !($child->error instanceof CancelledException)) {
                    $this->fail($child->error);
                }
                $this->childSettled($child);
            }
        }

        /** Drop a settled child. True O(1): swap the last element into its slot. */
        public function childSettled(Task $task): void
        {
            $i = $task->idx;
            if ($i < 0) { return; }
            $n = \count($this->children);
            if ($i >= $n || $this->children[$i] !== $task) { $task->idx = -1; return; }
            $last = $this->children[$n - 1];
            $this->children[$i] = $last;
            $last->idx = $i;
            \array_pop($this->children);
            $task->idx = -1;
        }

        /** Record the first real failure and cancel the remaining children. */
        public function fail(\Throwable $e): void
        {
            if ($e instanceof CancelledException) {
                // Our own cancellation coming back — not a new failure.
                $this->cancel();
                return;
            }
            if ($this->failure === null) {
                $this->failure = $e;
            }
            $this->cancel();
        }

        /**
         * Cancel every live child FOR REAL: a parked child is deregistered from
         * whatever it waits on and resumed with a CancelledException at its
         * suspend point; a child that itself opened scopes unwinds through their
         * joins, so cancellation is recursive by construction.
         */
        public function cancel(): void
        {
            if ($this->cancelled) { return; }
            $this->cancelled = true;
            // Handlers first: they release things the scheduler cannot see, and a
            // cancelled child may go on to touch them.
            $handlers = $this->cancelHandlers;
            $this->cancelHandlers = [];
            foreach ($handlers as $fn) {
                $fn();
            }
            $sched = Scheduler::instance();
            foreach ($this->children as $c) {
                $sched->cancelTask($c);
            }
        }
    }

    /**
     * A Go-style CSP channel over the cooperative scheduler. Unbuffered (cap 0) is
     * a rendezvous; buffered parks only when full (send) / empty (recv).
     * close() wakes everyone: pending recv returns null, pending/future send throws.
     */
    final class Channel
    {
        private int $cap;
        private bool $closed = false;

        /** @var mixed[] buffered values, FIFO (cap > 0 only) */
        private array $buffer = [];

        /** @var Task[] senders parked (buffer full / no receiver) */
        private array $sendQ = [];
        /** @var mixed[] the value each parked sender offers, parallel to $sendQ */
        private array $sendVal = [];

        /** @var Task[] receivers parked with nothing to take yet */
        private array $recvQ = [];

        public function __construct(int $capacity = 0)
        {
            $this->cap = $capacity < 0 ? 0 : $capacity;
        }

        public function isClosed(): bool
        {
            return $this->closed;
        }

        /** Send $value, suspending until it is buffered or taken by a receiver. */
        public function send(mixed $value): void
        {
            if ($this->closed) {
                throw new \RuntimeException("send on a closed channel");
            }
            $sched = Scheduler::instance();

            // A receiver is already parked → hand the value straight over.
            // Skip any waiter another channel already claimed, or one that died.
            while (\count($this->recvQ) > 0) {
                $r = \array_shift($this->recvQ);
                if ($this->wakeReceiver($r, $value, true)) {
                    return;
                }
            }

            if (\count($this->buffer) < $this->cap) {
                $this->buffer[] = $value;
                return;
            }

            $me = $sched->current();
            $this->sendQ[] = $me;
            $this->sendVal[] = $value;
            $sched->suspendCurrent();

            if ($this->closed) {
                throw new \RuntimeException("send on a closed channel");
            }
        }

        /** Receive the next value; null once closed and drained. */
        public function recv(): mixed
        {
            $sched = Scheduler::instance();

            if (\count($this->buffer) > 0) {
                $v = \array_shift($this->buffer);
                $this->promoteSender();
                return $v;
            }

            if (\count($this->sendQ) > 0) {
                $s = \array_shift($this->sendQ);
                $v = \array_shift($this->sendVal);
                $sched->wake($s);
                return $v;
            }

            if ($this->closed) {
                return null;
            }

            $me = $sched->current();
            $me->chanValue = null;
            $me->chanOk = true;
            $this->recvQ[] = $me;
            $sched->suspendCurrent();
            return $me->chanValue;
        }

        /**
         * Deliver ($value, $ok) to a parked receiver under the single-claim rule
         * that makes {@see select()} sound: a select-waiter parked on several
         * channels is won by the FIRST channel to reach it. Returns false when the
         * entry is stale (already claimed, or the task has settled/been cancelled)
         * so the caller discards it and tries the next receiver.
         */
        private function wakeReceiver(Task $r, mixed $value, bool $ok): bool
        {
            if ($r->state !== Task::PENDING) {
                return false;
            }
            if ($r->selecting) {
                if ($r->selectClaimed) {
                    return false;
                }
                $r->selectClaimed = true;
            }
            $r->chanValue = $value;
            $r->chanOk = $ok;
            $r->chanReady = $this;
            Scheduler::instance()->wake($r);
            return true;
        }

        /**
         * Non-blocking receive attempt for the select() fast path.
         * @return array{0: bool, 1: mixed, 2: bool} [taken, value, ok]
         */
        public function trySelectRecv(): array
        {
            if (\count($this->buffer) > 0) {
                $v = \array_shift($this->buffer);
                $this->promoteSender();
                return [true, $v, true];
            }
            if (\count($this->sendQ) > 0) {
                $s = \array_shift($this->sendQ);
                $v = \array_shift($this->sendVal);
                Scheduler::instance()->wake($s);
                return [true, $v, true];
            }
            if ($this->closed) {
                return [true, null, false];
            }
            return [false, null, false];
        }

        /** Park $t on this channel's receive queue (select() slow path). */
        public function registerSelectRecv(Task $t): void
        {
            $this->recvQ[] = $t;
        }

        /** Remove a parked receiver by identity. Rebuild — array_splice is unimplemented. */
        public function removeRecvWaiter(Task $t): void
        {
            /** @var Task[] $kept */
            $kept = [];
            foreach ($this->recvQ as $r) {
                if ($r !== $t) {
                    $kept[] = $r;
                }
            }
            $this->recvQ = $kept;
        }

        /** Move one parked sender's value into a buffer slot freed by a recv. */
        private function promoteSender(): void
        {
            if (\count($this->sendQ) > 0 && \count($this->buffer) < $this->cap) {
                $s = \array_shift($this->sendQ);
                $v = \array_shift($this->sendVal);
                $this->buffer[] = $v;
                Scheduler::instance()->wake($s);
            }
        }

        /** Close: parked receivers get null, parked/future senders throw. */
        public function close(): void
        {
            if ($this->closed) {
                return;
            }
            $this->closed = true;
            $sched = Scheduler::instance();

            foreach ($this->recvQ as $r) {
                $this->wakeReceiver($r, null, false);
            }
            $this->recvQ = [];

            foreach ($this->sendQ as $s) {
                $sched->wake($s);
            }
            $this->sendQ = [];
            $this->sendVal = [];
        }
    }

    /**
     * The ambient async context: the scope open in the CALLING task, plus the
     * cooperative half of cancellation. Real cancellation is a Fiber::throw at the
     * next suspend point; throwIfCancelled() is the checkpoint for a CPU-bound
     * loop that never suspends.
     */
    final class Context
    {
        /** The innermost scope open in the calling task, or null outside async(). */
        public static function currentScope(): ?TaskGroup
        {
            return Scheduler::instance()->currentGroup();
        }

        /** The cancellation handle of the scope the calling task is in. */
        public static function token(): CancellationToken
        {
            $group = self::currentScope();
            if ($group === null) {
                throw new \LogicException('Context::token() outside Async\\async() — no scope');
            }
            return $group->token();
        }

        /** True once the calling task's scope (or an ancestor) has been cancelled. */
        public static function isCancelled(): bool
        {
            $group = self::currentScope();
            while ($group !== null) {
                if ($group->cancelled) { return true; }
                $group = $group->parent;
            }
            return false;
        }

        /** Cancellation checkpoint — call at loop heads in long CPU-bound tasks. */
        public static function throwIfCancelled(): void
        {
            if (self::isCancelled()) {
                throw new CancelledException('task cancelled');
            }
        }

        /** The effective deadline (unix time) of the calling task, or null. */
        public static function deadline(): ?float
        {
            $group = self::currentScope();
            return $group === null ? null : $group->deadlineAt();
        }

        /** Seconds left before the deadline; null when unbounded. */
        public static function remaining(): ?float
        {
            $d = self::deadline();
            if ($d === null) { return null; }
            $left = $d - \microtime(true);
            return $left > 0.0 ? $left : 0.0;
        }
    }

    /**
     * The engine: a run queue, an Io\Poll reactor and a timer heap. One per
     * async() call — the singleton is torn down when run() returns, so repeated
     * async() calls in one program are fully independent.
     */
    final class Scheduler
    {
        private static ?Scheduler $instance = null;

        /** @var Task[] tasks ready to resume (the run queue) */
        private array $ready = [];
        private ?Task $running = null;
        /** Tasks created and not yet settled — deadlock detection. */
        private int $live = 0;

        private \Io\Poll\Context $reactor;
        /** @var array<int, \Io\Poll\Watcher> fd → its PERSISTENT reactor watcher */
        private array $connWatcher = [];
        /** @var array<int, Task> fd → the task parked on readability */
        private array $readWaiter = [];
        /** @var array<int, Task> fd → the task parked on writability */
        private array $writeWaiter = [];
        /** @var array<int, bool> fd → is Write currently armed on its watcher */
        private array $writeArmed = [];
        /** Parked-on-I/O task count (NOT registered-fd count: an idle fd is not work). */
        private int $ioWaiters = 0;

        /** @var float[] binary min-heap of deadlines, parallel to tmTask */
        private array $tmDeadline = [];
        /** @var Task[] the task each heap slot belongs to */
        private array $tmTask = [];
        /** Live (non-cancelled) timer count — cancelled slots are deleted lazily. */
        private int $tmLive = 0;

        private function __construct()
        {
            $this->reactor = new \Io\Poll\Context(\Io\Poll\Backend::Auto);
        }

        public static function instance(): Scheduler
        {
            if (self::$instance === null) {
                self::$instance = new Scheduler();
            }
            return self::$instance;
        }

        /** The innermost scope open in the RUNNING task. Null outside the loop. */
        public function currentGroup(): ?TaskGroup
        {
            $r = $this->running;
            return $r === null ? null : $r->scope;
        }

        public function current(): Task
        {
            return $this->running;
        }

        /** This task's recv scratch buffer, grown to at least $len bytes. */
        public function readBuf(int $len): \Ffi\Ptr
        {
            $me = $this->running;
            if ($me->rbuf === null || $len > $me->rbufLen) {
                if ($me->rbuf !== null) { \Runtime\Libc\free($me->rbuf); }
                $me->rbuf = \Runtime\Libc\calloc($len, 1);
                $me->rbufLen = $len;
            }
            return $me->rbuf;
        }

        /** @param mixed[] $args */
        public function newTask(callable $fn, array $args, TaskGroup $owner): Task
        {
            $fiber = new \Fiber(function () use ($fn, $args) {
                return $fn(...$args);
            });
            $task = new Task($fiber, $owner, $owner);
            $this->live = $this->live + 1;
            $task->queued = true;
            $this->ready[] = $task;
            return $task;
        }

        // ── the run entry ──────────────────────────────────────────────────
        public function run(callable $main): mixed
        {
            $root = new TaskGroup(null);
            $rootTask = $this->newTask($main, [], $root);
            $rootTask->idx = 0;
            $root->children[] = $rootTask;
            $this->installNetpoller();
            $this->loop();
            $this->clearNetpoller();

            $stuck = $this->live > 0;
            $failure = $root->failure;
            $result = $rootTask->result;
            // Dropping the singleton drops the reactor (Io\Poll\Context::__destruct
            // closes its kqueue/epoll fd) and every queue — the next async() in the
            // same program starts from a clean engine.
            self::$instance = null;
            if ($failure !== null) {
                throw $failure;
            }
            if ($stuck) {
                throw new DeadlockException('async: all tasks are asleep — deadlock');
            }
            return $result;
        }

        // ── transparent I/O seam ───────────────────────────────────────────
        // Plain fread/fwrite/stream_socket_accept/fclose consult \Runtime\AsyncHook
        // and route their would-block through these, so ordinary blocking-looking
        // PHP I/O suspends the fiber instead of the process (Go netpoller model).
        private function installNetpoller(): void
        {
            // Method call (links by symbol across the stdlib boundary), not a direct
            // AsyncHook::$prop write — a cross-unit static-prop store needs class
            // metadata the .sig does not export. AsyncHook assigns via self:: inside.
            \Runtime\AsyncHook::install(
                function (\Resource $s): void { $this->waitReadable($s); },
                function (\Resource $s): void { $this->waitWritable($s); },
                function (\Resource $s): void { $this->closeConn($s); },
            );
        }

        private function clearNetpoller(): void
        {
            \Runtime\AsyncHook::clear();
        }

        private function loop(): void
        {
            while (true) {
                // Snapshot the batch: a task that re-queues itself must not starve
                // the reactor by keeping the queue permanently non-empty.
                $batch = $this->ready;
                $this->ready = [];
                foreach ($batch as $task) {
                    if ($task->state !== Task::PENDING) { $task->queued = false; continue; }
                    $this->step($task);
                }
                if (\count($this->ready) > 0) { continue; }
                if ($this->ioWaiters === 0 && $this->tmLive === 0) { return; }
                $this->pollAndTick();
            }
        }

        /** Resume one task's fiber; settle it when the fiber finishes/throws. */
        private function step(Task $task): void
        {
            $task->queued = false;
            $prev = $this->running;
            $this->running = $task;
            try {
                if (!$task->fiber->isStarted()) {
                    $task->fiber->start();
                } elseif ($task->pendingThrow !== null) {
                    $e = $task->pendingThrow;
                    $task->pendingThrow = null;
                    $task->fiber->throw($e);
                } else {
                    $task->fiber->resume();
                }
            } catch (\Throwable $e) {
                $this->running = $prev;
                $this->settle($task, Task::FAILED, null, $e);
                $task->fiber->reclaim();      // terminated via exception → free stack now
                return;
            }
            $this->running = $prev;
            if ($task->fiber->isTerminated()) {
                $this->settle($task, Task::DONE, $task->fiber->getReturn(), null);
                $task->fiber->reclaim();      // free+pool the stack now, not at __destruct
            }
        }

        private function settle(Task $task, int $state, mixed $result, ?\Throwable $error): void
        {
            $task->state = $state;
            $task->result = $result;
            $task->error = $error;
            $this->live = $this->live - 1;
            if ($task->rbuf !== null) {
                \Runtime\Libc\free($task->rbuf);
                $task->rbuf = null;
                $task->rbufLen = 0;
            }
            $waiters = $task->waiters;
            $task->waiters = [];
            foreach ($waiters as $w) {
                $this->wake($w);
            }
            // An unclaimed failure has no one to report it — surface it through the
            // owning scope so it is never silently lost. A CancelledException is the
            // scope's OWN doing, not a new failure.
            if ($state === Task::FAILED && !$task->claimed && !($error instanceof CancelledException)) {
                $task->owner->fail($error);
            }
            // Prune from the scope so a long-lived group (a server root) does not
            // accumulate settled tasks. An awaiter holds its own reference.
            $task->owner->childSettled($task);
        }

        // ── suspend / wake / cancel ────────────────────────────────────────
        /**
         * Yield the running task back to the loop. A cancellation requested while
         * this task was running lands HERE — the first suspend point after it.
         */
        public function suspendCurrent(): void
        {
            $this->checkCancel();
            \Fiber::suspend();
        }

        /**
         * Deliver a pending cancellation BEFORE the caller registers itself
         * anywhere. Every park site calls this first, then registers, then
         * suspends — registering and only then throwing would leave a live
         * reactor slot / timer reservation behind on the way out.
         */
        public function checkCancel(): void
        {
            $me = $this->running;
            if ($me !== null && $me->pendingThrow !== null) {
                $e = $me->pendingThrow;
                $me->pendingThrow = null;
                throw $e;
            }
        }

        public function wake(Task $task): void
        {
            if ($task->state === Task::PENDING && !$task->queued) {
                $task->queued = true;
                $this->ready[] = $task;
            }
        }

        /**
         * Cancel $task for real. A parked task is deregistered from whatever it
         * waits on (reactor slot / timer) and resumed with a CancelledException at
         * its suspend point; the RUNNING task just gets the flag and hits it at its
         * next suspend. Channel queues are not walked — a stale entry is dropped by
         * {@see Channel::wakeReceiver()}'s liveness check.
         */
        public function cancelTask(Task $task): void
        {
            if ($task->state !== Task::PENDING) { return; }
            if ($task->pendingThrow !== null) { return; }
            $task->pendingThrow = new CancelledException('task cancelled');
            if ($task === $this->running) { return; }
            $this->releaseIo($task);
            if ($task->timerActive) {
                $task->timerActive = false;
                $this->tmLive = $this->tmLive - 1;
            }
            $task->selecting = false;
            $this->wake($task);
        }

        /** Drop $task's reactor registration, if any. Idempotent. */
        private function releaseIo(Task $task): void
        {
            $fd = $task->ioFd;
            if ($fd < 0) { return; }
            $task->ioFd = -1;
            $this->ioWaiters = $this->ioWaiters - 1;
            if ($task->ioWrite) {
                if (isset($this->writeWaiter[$fd]) && $this->writeWaiter[$fd] === $task) {
                    unset($this->writeWaiter[$fd]);
                    $this->disarmWrite($fd);
                }
            } else {
                if (isset($this->readWaiter[$fd]) && $this->readWaiter[$fd] === $task) {
                    unset($this->readWaiter[$fd]);
                }
            }
        }

        // ── timers (binary min-heap, lazy delete) ──────────────────────────
        public function sleep(float $seconds): void
        {
            $this->checkCancel();
            $me = $this->running;
            $me->timerActive = true;
            $this->tmLive = $this->tmLive + 1;
            $this->timerPush(\microtime(true) + $seconds, $me);
            \Fiber::suspend();
            if ($me->timerActive) {
                // Woken by something other than the timer (cancel already cleared
                // the flag) — drop the reservation; the heap slot dies lazily.
                $me->timerActive = false;
                $this->tmLive = $this->tmLive - 1;
            }
        }

        /**
         * Park until $t settles OR $deadline (a unix time) passes. Returns true
         * when the task settled, false on expiry — it does NOT cancel or throw,
         * so the caller decides what a timeout means. The one primitive under
         * {@see Task::awaitWithin()} and {@see timeout()}.
         */
        public function awaitDeadline(Task $t, float $deadline): bool
        {
            $me = $this->running;
            $t->claimed = true;   // we are handling its outcome — do not escalate
            while ($t->state === Task::PENDING) {
                if (\microtime(true) >= $deadline) {
                    return false;
                }
                $this->checkCancel();
                $t->addWaiter($me);
                $me->timerActive = true;
                $this->tmLive = $this->tmLive + 1;
                $this->timerPush($deadline, $me);
                \Fiber::suspend();
                if ($me->timerActive) {
                    // The task settled first — drop the reservation (the heap slot
                    // dies lazily on the next prune).
                    $me->timerActive = false;
                    $this->tmLive = $this->tmLive - 1;
                }
            }
            return true;
        }

        private function timerPush(float $deadline, Task $t): void
        {
            $this->tmDeadline[] = $deadline;
            $this->tmTask[] = $t;
            $i = \count($this->tmDeadline) - 1;
            while ($i > 0) {
                $p = (int)(($i - 1) / 2);
                if ($this->tmDeadline[$p] <= $this->tmDeadline[$i]) { break; }
                $d = $this->tmDeadline[$p]; $this->tmDeadline[$p] = $this->tmDeadline[$i]; $this->tmDeadline[$i] = $d;
                $k = $this->tmTask[$p]; $this->tmTask[$p] = $this->tmTask[$i]; $this->tmTask[$i] = $k;
                $i = $p;
            }
        }

        private function timerPop(): void
        {
            $n = \count($this->tmDeadline);
            if ($n === 0) { return; }
            $last = $n - 1;
            $this->tmDeadline[0] = $this->tmDeadline[$last];
            $this->tmTask[0] = $this->tmTask[$last];
            \array_pop($this->tmDeadline);
            \array_pop($this->tmTask);
            $n = $n - 1;
            $i = 0;
            while (true) {
                $l = 2 * $i + 1;
                $r = $l + 1;
                $m = $i;
                if ($l < $n && $this->tmDeadline[$l] < $this->tmDeadline[$m]) { $m = $l; }
                if ($r < $n && $this->tmDeadline[$r] < $this->tmDeadline[$m]) { $m = $r; }
                if ($m === $i) { break; }
                $d = $this->tmDeadline[$m]; $this->tmDeadline[$m] = $this->tmDeadline[$i]; $this->tmDeadline[$i] = $d;
                $k = $this->tmTask[$m]; $this->tmTask[$m] = $this->tmTask[$i]; $this->tmTask[$i] = $k;
                $i = $m;
            }
        }

        /** Drop dead heap tops so the head is always a live timer. */
        private function timerPrune(): void
        {
            while (\count($this->tmTask) > 0) {
                $t = $this->tmTask[0];
                if ($t->timerActive && $t->state === Task::PENDING) { return; }
                $this->timerPop();
            }
        }

        // ── I/O readiness (PERSISTENT registration, per-fd waiter slots) ────
        // A connection's fd is registered ONCE (level-triggered Read) and kept for
        // its life — no add/remove kevent per read. Safe because a fiber only parks
        // after recv/send returned EWOULDBLOCK (the fd is drained), so a
        // level-triggered watcher does not re-fire until NEW readiness arrives.
        // The watcher carries the FD, not a task: two fibers may legitimately wait
        // on one fd (a reader and a writer), so the waiter lives in a per-fd slot.

        private function ensureWatcher(\Resource $conn, int $fd): void
        {
            if (!isset($this->connWatcher[$fd])) {
                $this->connWatcher[$fd] = $this->reactor->add(
                    new \StreamPollHandle($conn), [\Io\Poll\Event::Read], $fd);
                $this->writeArmed[$fd] = false;
            }
        }

        /** Suspend until $conn is readable. */
        public function waitReadable(\Resource $conn): void
        {
            $this->checkCancel();
            $me = $this->running;
            $fd = $conn->addr;
            $this->ensureWatcher($conn, $fd);
            $this->readWaiter[$fd] = $me;
            $me->ioFd = $fd;
            $me->ioWrite = false;
            $this->ioWaiters = $this->ioWaiters + 1;
            \Fiber::suspend();
            $this->releaseIo($me);
        }

        /** Suspend until $conn is writable — arms Write only while a writer waits. */
        public function waitWritable(\Resource $conn): void
        {
            $this->checkCancel();
            $me = $this->running;
            $fd = $conn->addr;
            $this->ensureWatcher($conn, $fd);
            $this->writeWaiter[$fd] = $me;
            if (!$this->writeArmed[$fd]) {
                $this->connWatcher[$fd]->modifyEvents([\Io\Poll\Event::Read, \Io\Poll\Event::Write]);
                $this->writeArmed[$fd] = true;
            }
            $me->ioFd = $fd;
            $me->ioWrite = true;
            $this->ioWaiters = $this->ioWaiters + 1;
            \Fiber::suspend();
            $this->releaseIo($me);
        }

        /** Disarm Write once no writer is parked on $fd. */
        private function disarmWrite(int $fd): void
        {
            if (isset($this->writeArmed[$fd]) && $this->writeArmed[$fd] && !isset($this->writeWaiter[$fd])) {
                if (isset($this->connWatcher[$fd])) {
                    $this->connWatcher[$fd]->modifyEvents([\Io\Poll\Event::Read]);
                }
                $this->writeArmed[$fd] = false;
            }
        }

        /** Drop $conn's persistent watcher (call before fclose so it stops firing). */
        public function closeConn(\Resource $conn): void
        {
            $fd = $conn->addr;
            if (isset($this->connWatcher[$fd])) {
                $this->connWatcher[$fd]->remove();
                unset($this->connWatcher[$fd]);
                unset($this->writeArmed[$fd]);
            }
            // A task still parked on a closing fd would never be woken — resume it
            // so its read/write returns rather than wedging the loop.
            if (isset($this->readWaiter[$fd])) {
                $t = $this->readWaiter[$fd];
                unset($this->readWaiter[$fd]);
                $t->ioFd = -1;
                $this->ioWaiters = $this->ioWaiters - 1;
                $this->wake($t);
            }
            if (isset($this->writeWaiter[$fd])) {
                $t = $this->writeWaiter[$fd];
                unset($this->writeWaiter[$fd]);
                $t->ioFd = -1;
                $this->ioWaiters = $this->ioWaiters - 1;
                $this->wake($t);
            }
        }

        // ── the blocking step: wait for fds / the next timer, wake tasks ────
        private function pollAndTick(): void
        {
            $this->timerPrune();
            $timeout = -1.0;
            if (\count($this->tmDeadline) > 0) {
                $timeout = $this->tmDeadline[0] - \microtime(true);
                if ($timeout < 0) { $timeout = 0.0; }
            }

            if ($this->ioWaiters > 0) {
                $sec = $timeout < 0 ? null : (int)$timeout;
                $usec = $timeout < 0 ? 0 : (int)(($timeout - (int)$timeout) * 1000000);
                /** @var \Io\Poll\Watcher[] $ready */
                $ready = $this->reactor->wait($sec, $usec, null);
                foreach ($ready as $watcher) {
                    $fd = $watcher->getData();
                    $hup = $watcher->hasTriggered(\Io\Poll\Event::Error)
                        || $watcher->hasTriggered(\Io\Poll\Event::HangUp);
                    if (($hup || $watcher->hasTriggered(\Io\Poll\Event::Read))
                        && isset($this->readWaiter[$fd])) {
                        $t = $this->readWaiter[$fd];
                        unset($this->readWaiter[$fd]);
                        $t->ioFd = -1;
                        $this->ioWaiters = $this->ioWaiters - 1;
                        $this->wake($t);
                    }
                    if (($hup || $watcher->hasTriggered(\Io\Poll\Event::Write))
                        && isset($this->writeWaiter[$fd])) {
                        $t = $this->writeWaiter[$fd];
                        unset($this->writeWaiter[$fd]);
                        $t->ioFd = -1;
                        $this->ioWaiters = $this->ioWaiters - 1;
                        $this->wake($t);
                        $this->disarmWrite($fd);
                    }
                }
            } elseif ($timeout > 0) {
                \usleep((int)($timeout * 1000000));
            }
            $this->fireTimers();
        }

        private function fireTimers(): void
        {
            $now = \microtime(true);
            while (\count($this->tmDeadline) > 0) {
                $this->timerPrune();
                if (\count($this->tmDeadline) === 0) { return; }
                if ($this->tmDeadline[0] > $now) { return; }
                $t = $this->tmTask[0];
                $this->timerPop();
                $t->timerActive = false;
                $this->tmLive = $this->tmLive - 1;
                $this->wake($t);
            }
        }
    }

    // ── public API ─────────────────────────────────────────────────────────

    /**
     * Run $main to completion on the async engine and RETURN ITS VALUE, so an
     * async block composes like an ordinary call:
     *
     *   $rows = Async\async(fn() => Async\awaitAll($a, $b));
     *
     * Opens the implicit ROOT scope, so a top-level {@see spawn()} is still
     * structured. Throws whatever escaped the root scope, or a
     * {@see DeadlockException} if the loop ran dry with tasks still parked.
     */
    function async(callable $main): mixed
    {
        return Scheduler::instance()->run($main);
    }

    /**
     * Open a child scope and return the body's value — the thin, everyday form of
     * {@see TaskGroup::run()}. Returns only once every task spawned inside has
     * settled; the first failure cancels the rest and propagates.
     *
     *   $total = Async\group(function (TaskGroup $g) {
     *       $a = $g->spawn(fn() => fetch(1));
     *       $b = $g->spawn(fn() => fetch(2));
     *       return $a->await() + $b->await();
     *   });
     */
    function group(callable $body): mixed
    {
        return TaskGroup::run($body);
    }

    /**
     * Run $body in a scope that must finish within $seconds. On expiry the whole
     * scope — the body AND everything it spawned — is cancelled and joined, then
     * {@see TimeoutException} is thrown. A timeout that leaves work running is
     * not a timeout.
     *
     *   $page = Async\timeout(2.0, fn() => file_get_contents($url));
     *
     * The deadline is visible to the code inside via `Context::remaining()`, and
     * it only ever TIGHTENS an enclosing one ({@see TaskGroup::deadlineAt()}).
     */
    function timeout(float $seconds, callable $body): mixed
    {
        $sched = Scheduler::instance();
        if ($sched->currentGroup() === null) {
            throw new \LogicException('timeout() outside Async\\async() — no scope');
        }
        $cur = $sched->current();
        $group = new TaskGroup($cur->scope);
        $deadline = \microtime(true) + $seconds;
        // An enclosing deadline still wins if it is nearer.
        $group->deadline = $deadline;
        $effective = $group->deadlineAt();
        $prev = $cur->scope;
        $cur->scope = $group;

        // The body runs as a CHILD, not in this fiber: only a separate task can be
        // cancelled at its suspend point while we keep the timer.
        $task = $group->spawn(function () use ($body, $group) { return $body($group); });
        $settled = $sched->awaitDeadline($task, $effective);
        if (!$settled) {
            $group->cancel();
        }
        $group->joinAll();
        $cur->scope = $prev;

        if (!$settled) {
            throw new TimeoutException('timed out after ' . (string)$seconds . 's');
        }
        if ($group->failure !== null) {
            throw $group->failure;
        }
        if ($task->state === Task::FAILED) {
            throw $task->error;
        }
        return $task->result;
    }

    /**
     * Spawn a concurrent task into the scope open in THIS task, returning a handle.
     * Never fire-and-forget: the task is owned by, and joined at the end of, that
     * scope.
     */
    function spawn(callable $fn, mixed ...$args): Task
    {
        $group = Scheduler::instance()->currentGroup();
        if ($group === null) {
            throw new \LogicException('spawn() outside Async\\async() — no scope to own the task');
        }
        return $group->spawn($fn, ...$args);
    }

    /**
     * Wait for every task and return their results keyed by INPUT position.
     *
     * FAIL-FAST: the first failure cancels the tasks still running, joins them, and
     * rethrows that error — the structured-concurrency contract (Go errgroup /
     * Kotlin coroutineScope), not Promise.allSettled. Every input is claimed BEFORE
     * parking, so a sibling that fails while this call is parked elsewhere is not
     * mistaken for an unhandled failure and escalated behind our back.
     *
     * @return mixed[] results keyed by input position (0..N-1)
     */
    function awaitAll(Task ...$tasks): array
    {
        $n = \count($tasks);
        if ($n === 0) { return []; }
        $sched = Scheduler::instance();
        // Claim first, park second: no window in which a failure looks unhandled.
        foreach ($tasks as $t) { $t->claimed = true; }

        $failure = null;
        while (true) {
            $pending = 0;
            foreach ($tasks as $t) {
                if ($t->state === Task::FAILED && $failure === null) { $failure = $t->error; }
                if ($t->state === Task::PENDING) { $pending = $pending + 1; }
            }
            if ($failure !== null || $pending === 0) { break; }
            foreach ($tasks as $t) {
                if ($t->state === Task::PENDING) { $t->addWaiter($sched->current()); }
            }
            $sched->suspendCurrent();
        }

        if ($failure !== null) {
            foreach ($tasks as $t) { $sched->cancelTask($t); }
            // Join the cancelled siblings so none outlives this call.
            foreach ($tasks as $t) {
                while ($t->state === Task::PENDING) {
                    $t->addWaiter($sched->current());
                    $sched->suspendCurrent();
                }
            }
            throw $failure;
        }

        /** @var mixed[] $results */
        $results = [];
        foreach ($tasks as $i => $t) { $results[$i] = $t->result; }
        return $results;
    }

    /**
     * Wait for the FIRST successful task and return its value (Promise.any). No
     * watcher fibers: this parks directly on the inputs, so nothing is orphaned
     * when a winner appears. If every task failed, throws an {@see AggregateError}
     * with the errors keyed by INPUT position.
     */
    function awaitAny(Task ...$tasks): mixed
    {
        $n = \count($tasks);
        if ($n === 0) {
            throw new \LogicException('awaitAny() with no tasks blocks forever');
        }
        $sched = Scheduler::instance();
        foreach ($tasks as $t) { $t->claimed = true; }

        while (true) {
            $pending = 0;
            foreach ($tasks as $t) {
                if ($t->state === Task::DONE) { return $t->result; }
                if ($t->state === Task::PENDING) { $pending = $pending + 1; }
            }
            if ($pending === 0) { break; }
            foreach ($tasks as $t) {
                if ($t->state === Task::PENDING) { $t->addWaiter($sched->current()); }
            }
            $sched->suspendCurrent();
        }

        /** @var array<int, \Throwable> $errors */
        $errors = [];
        foreach ($tasks as $i => $t) { $errors[$i] = $t->error; }
        throw new AggregateError('awaitAny: all tasks failed', $errors);
    }

    /** Suspend the current task for $seconds without blocking the loop. */
    function delay(float $seconds): void
    {
        Scheduler::instance()->sleep($seconds);
    }

    /** Make a channel (Go's `make(chan, cap)`); capacity 0 = unbuffered rendezvous. */
    function channel(int $capacity = 0): Channel
    {
        return new Channel($capacity);
    }

    /**
     * Receive from whichever of $channels is ready first (Go's `select` over
     * receives). Parks on all of them; the single-claim rule in
     * {@see Channel::wakeReceiver()} guarantees exactly one case fires.
     *
     * @param Channel[] $channels
     * @return array{0: int, 1: mixed, 2: bool} [index that fired, value, ok]
     */
    function select(array $channels): array
    {
        if (\count($channels) === 0) {
            throw new \LogicException('select() with no channels blocks forever');
        }
        $sched = Scheduler::instance();

        foreach ($channels as $i => $ch) {
            $r = $ch->trySelectRecv();
            if ($r[0]) {
                return [$i, $r[1], $r[2]];
            }
        }

        $me = $sched->current();
        $me->selecting = true;
        $me->selectClaimed = false;
        $me->chanReady = null;
        $me->chanValue = null;
        $me->chanOk = true;
        foreach ($channels as $ch) {
            $ch->registerSelectRecv($me);
        }
        $sched->suspendCurrent();

        $me->selecting = false;
        $fired = $me->chanReady;
        $idx = -1;
        foreach ($channels as $i => $ch) {
            if ($ch === $fired) {
                $idx = $i;
            } else {
                $ch->removeRecvWaiter($me);
            }
        }
        return [$idx, $me->chanValue, $me->chanOk];
    }

    // ── raw async socket helpers ───────────────────────────────────────────
    // These bypass the stream layer for the hot path. Ordinary fread/fwrite work
    // too (via the netpoller seam) — use those unless you are chasing syscalls.

    /** Connect to $addr (e.g. "tcp://127.0.0.1:8080"), returning a non-blocking stream. */
    function connect(string $addr): \Resource
    {
        $errno = 0;
        $errstr = "";
        $conn = \stream_socket_client($addr, $errno, $errstr);
        if ($conn === false) {
            throw new \RuntimeException("connect failed: " . $errstr);
        }
        \stream_set_blocking($conn, false);
        return $conn;
    }

    /** Accept the next connection, suspending until one arrives. Non-blocking ONCE. */
    function accept(\Resource $server): \Resource
    {
        while (true) {
            $conn = \stream_socket_accept($server, 0);
            if ($conn !== false) {
                \stream_set_blocking($conn, false);
                return $conn;
            }
            Scheduler::instance()->waitReadable($server);
        }
    }

    /** Drop the connection's persistent reactor watcher, then close it. */
    function close(\Resource $conn): void
    {
        Scheduler::instance()->closeConn($conn);
        \fclose($conn);
    }

    /** Read up to $length bytes (raw recv), suspending until readable. "" at EOF. */
    function read(\Resource $conn, int $length): string
    {
        $fd = $conn->addr;
        $sched = Scheduler::instance();
        $buf = $sched->readBuf($length);         // per-task, no per-read calloc/free
        $n = \Async\sys_recv($fd, $buf, $length, 0);
        if ($n > 0) {
            return \str_from_buffer($buf, $n);
        }
        if ($n === 0) {
            return '';                           // clean EOF
        }
        // n < 0 = EWOULDBLOCK. Park, then read ONCE more. With a level-triggered
        // watcher a <= 0 AFTER a readability wake is EOF or a hard error
        // (ECONNRESET) rather than would-block, so we report closed instead of
        // spinning forever on a reset connection (no errno binding to tell apart).
        $sched->waitReadable($conn);
        $buf = $sched->readBuf($length);
        $n = \Async\sys_recv($fd, $buf, $length, 0);
        if ($n > 0) {
            return \str_from_buffer($buf, $n);
        }
        return '';                               // EOF or error → closed
    }

    /** Write all of $data (raw send), suspending on back-pressure. */
    function write(\Resource $conn, string $data): int
    {
        $fd = $conn->addr;
        $len = \strlen($data);
        $total = 0;
        $sched = Scheduler::instance();
        while ($total < $len) {
            $chunk = $total === 0 ? $data : \substr($data, $total);
            $n = \Async\sys_send($fd, $chunk, $len - $total, 0);
            if ($n > 0) {
                $total = $total + $n;
                continue;
            }
            if ($n === 0) {
                break;
            }
            // n < 0 = back-pressure. Wait for writability, retry ONCE; a second
            // <= 0 is a hard error (EPIPE / reset), not would-block.
            $sched->waitWritable($conn);
            $n = \Async\sys_send($fd, $chunk, $len - $total, 0);
            if ($n > 0) {
                $total = $total + $n;
            } else {
                break;
            }
        }
        return $total;
    }

    // ── shared-nothing multi-process ───────────────────────────────────────
    // The Zend scaling model: fork N workers, each with its OWN scheduler and
    // reactor, sharing a prebound listener (prefork) or binding via SO_REUSEPORT.

    /**
     * Fork into $n workers. Returns THIS worker's index: 0 for the original
     * process, 1..$n-1 for the forks. Call BEFORE async() — each worker then runs
     * its own scheduler. A failed fork degrades to fewer workers.
     */
    function workers(int $n): int
    {
        for ($i = 1; $i < $n; $i++) {
            $pid = \Async\sys_fork();
            if ($pid === 0) {
                return $i;              // child → worker $i
            }
            if ($pid < 0) {
                return 0;               // fork failed — carry on with what we have
            }
        }
        return 0;                       // the original process = worker 0
    }

    /** This process's pid — handy for a per-worker log line. */
    function pid(): int
    {
        return \Async\sys_getpid();
    }
}
