<?php
// Async\ — green threads with structured concurrency. Cheap stackful fibers
// multiplexed by a cooperative scheduler over an Io\Poll reactor (kqueue/epoll)
// and a timer heap. Blocking-LOOKING I/O that transparently suspends: the
// netpoller seam (\Runtime\AsyncHook) lets the ordinary stdlib stream layer —
// fread/fwrite/stream_socket_accept/fclose, and everything above them — park the
// calling fiber instead of the process.
//
// The concurrency MODEL is Go's (cheap tasks, channels, a netpoller). The
// SPELLING is PHP's: objects instead of multi-return tuples, `foreach` instead
// of a comma-ok loop, typed exceptions instead of sentinels, plain streams
// instead of a hand-rolled fd layer.
//
// DEMAND-GATED (Main.php): a program that never names Async\ carries none of it.
// Pulls in \Fiber and Io\Poll, so the gate forces those two on as well.
//
// STRUCTURED, by construction:
//   - every task is owned by a scope (TaskGroup); async() opens an implicit ROOT
//     scope, so a top-level spawn() is still owned — no fire-and-forget;
//   - a scope does not close until every child has settled;
//   - the first child failure CANCELS its siblings for real and propagates out;
//   - cancellation is STICKY: once requested it is re-raised at EVERY suspend
//     point, so a blanket `catch` cannot pin a scope open (use shield() for
//     cleanup that must itself suspend);
//   - a failure nobody claimed is escalated to the owning scope, never dropped;
//   - if the loop runs out of work while tasks are still parked, that is a
//     DEADLOCK and it is reported, not silently exited.
//
// ⚠ NOT async: regular-FILE I/O. O_NONBLOCK is a no-op for regular files on both
// Linux and macOS, and there is no thread pool / aio / io_uring here, so
// file_get_contents('/path'), fopen()+fread() on a KIND_FILE handle and friends
// block the whole loop. Network I/O (connect, TLS handshake, read, write,
// accept) is async; getaddrinfo is still synchronous.
//
// ★ THE INVARIANT WORTH KNOWING: the scheduler is cooperative and single-
// threaded, so nothing else runs between two suspend points. Everything between
// them is a critical section for free — an ordinary `$x->n = $x->n + 1` is
// already atomic by construction. That is why this runtime has NO atomics: there
// is no data race to protect against (the forked workers() are shared-nothing;
// atomics would only mean something under real shared-memory threading). What
// DOES need protecting is a section that SUSPENDS in the middle, letting other
// tasks observe half-updated state — that is what Mutex is for.
//
// ⚠ NO `static` LOCALS anywhere in this file: a static local is backed by a
// module global (Compile\Mir\LocalSlots), i.e. one cell shared by every fiber,
// so it corrupts under concurrency. Per-task state lives on Task.

namespace Async {

    // ── errors ─────────────────────────────────────────────────────────────

    /**
     * Raised inside a task whose scope was cancelled. Extends \Error, NOT
     * \Exception, on purpose: `catch (\Exception $e)` — the blanket catch most
     * PHP code is written with — must not be able to eat cancellation. (Trio
     * picks BaseException for the same reason.) A `catch (\Throwable)` still
     * sees it, which is why cancellation is also STICKY: swallowing it once only
     * buys the task until its next suspend point.
     */
    final class CancelledException extends \Error {}

    /** A deadline elapsed before the work finished. {@see timeout()}, {@see Task::awaitWithin()}. */
    final class TimeoutException extends \RuntimeException {}

    /** The loop ran out of work while tasks were still parked — nothing can wake them. */
    final class DeadlockException extends \RuntimeException {}

    /** send() on a channel that is already closed. */
    final class ChannelClosedException extends \RuntimeException {}

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

    // ── results (objects, not tuples) ───────────────────────────────────────

    /**
     * One receive from a channel. Go returns `v, ok`; PHP has no multi-return,
     * and collapsing it into "null means closed" makes null an illegal payload —
     * so it is an object.
     *
     *   $r = $ch->next();
     *   if (!$r->ok) { break; }        // closed and drained
     */
    final class Received
    {
        public function __construct(
            public mixed $value,
            public bool $ok,
        ) {}
    }

    /**
     * Which {@see select()} case fired, and with what.
     *
     * - `index`   — position in the case list handed to select()
     * - `value`   — the received value; always null for a send case
     * - `ok`      — false when the channel was closed rather than delivering
     * - `channel` — the channel that completed
     * - `isSend`  — true when the case that fired was a send
     */
    final class Selected
    {
        public function __construct(
            public int $index,
            public mixed $value,
            public bool $ok,
            public ?Channel $channel,
            public bool $isSend,
        ) {}
    }

    /**
     * How one task turned out, when you asked for the OUTCOME rather than the
     * value — {@see awaitAllSettled()} / {@see mapSettled()}. `ok` decides which
     * of `value` / `error` is meaningful.
     */
    final class Settled
    {
        public function __construct(
            public bool $ok,
            public mixed $value,
            public ?\Throwable $error,
        ) {}
    }

    /**
     * One arm of a {@see select()}. Build with the factories — a bare Channel in
     * the case list is also accepted as shorthand for `SelectCase::recv($ch)`.
     */
    final class SelectCase
    {
        public const RECV = 0;
        public const SEND = 1;

        public function __construct(
            public int $kind,
            public Channel $channel,
            public mixed $value,
        ) {}

        public static function recv(Channel $ch): SelectCase
        {
            return new SelectCase(self::RECV, $ch, null);
        }

        public static function send(Channel $ch, mixed $value): SelectCase
        {
            return new SelectCase(self::SEND, $ch, $value);
        }
    }

    /**
     * The READ half of cancellation — a handle you can pass anywhere (into a
     * helper, a library, a long loop) without also handing over the power to
     * cancel. The WRITE half is the scope itself: `TaskGroup::cancel()`. Keeping
     * them as two views of ONE object means there is no second cancellation
     * state to keep in sync with the scope tree — a token is just a view of a
     * `TaskGroup`, and cancellation walks the same parent chain the scheduler
     * already uses.
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
     * A running unit of async work — a fiber plus its settled state. Created by
     * {@see spawn()} / {@see TaskGroup::spawn()}, never constructed directly.
     * Its public fields are scheduler bookkeeping, not API.
     */
    /**
     * One task's interest in one fd, for `stream_select`/`socket_select`.
     *
     * The read/write paths chain waiters through `Task->ioNext` — ONE link, which
     * is all a task parked on a single fd needs. A select waiter sits in N chains
     * at once, so it gets a record per fd instead: `fdNext` chains the records
     * watching the same fd, `ownNext` chains the records owned by the same task,
     * and both are unlinked in one pass when the select ends. Records, not a
     * nested `array<int, Task[]>` — the element-repr hazard that shaped the
     * read/write chains applies here too.
     */
    final class SelectReg
    {
        public ?SelectReg $fdNext = null;
        public ?SelectReg $ownNext = null;

        public function __construct(
            public Task $task,
            public int $fd,
            public bool $write,
        ) {}
    }

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

        /**
         * STICKY cancellation. Once set, every resume and every park site raises
         * CancelledException until the task actually terminates — a one-shot
         * throw would let `try { … } catch (\Throwable) {}` pin the scope open
         * forever, which is exactly the shape real code is written in.
         */
        public bool $cancelRequested = false;
        /** Nesting depth of {@see shield()} — cancellation is held back while > 0. */
        public int $shield = 0;

        /** The fd this task is parked on, or -1. Owned by the scheduler. */
        public int $ioFd = -1;
        /** True when the park is for writability (else readability). */
        public bool $ioWrite = false;
        /**
         * INTRUSIVE chain of the other tasks parked on the SAME fd (the scheduler's
         * per-fd slot holds the head). Two readers on one stream are legal — and a
         * single-Task slot silently OVERWROTE the first one, so it was never woken
         * and its fread() returned empty while the other got the data. A chain on
         * the Task avoids a nested `array<int, Task[]>` in prelude code (the known
         * element-repr hazard) and pushes/pops in O(1).
         */
        public ?Task $ioNext = null;

        /** Armed while parked on a timer; cleared on wake/cancel (lazy heap delete). */
        public bool $timerActive = false;

        /** Head of this task's own {@see SelectReg} list, and how many it holds. */
        public ?SelectReg $selHead = null;
        public int $selFds = 0;
        /** Set by the reactor when one of those fds fired — select's "why did I wake". */
        public bool $selWoken = false;

        /**
         * A DAEMON task is work the program must not be kept alive for — the
         * signal pump is the only one today. It is owned and cancelled like any
         * other task, but it does not count towards "is there still work?", so a
         * program whose real tasks have all finished exits instead of being
         * pinned open by the pump's own timer.
         */
        public bool $daemon = false;

        /** A value handed to this task while it was parked on a {@see Channel}. */
        public mixed $chanValue = null;
        /** False when the delivering channel was closed (recv/select report !ok). */
        public bool $chanOk = true;
        /** True while parked across several channels in a {@see select()}. */
        public bool $selecting = false;
        /** Set once ONE channel commits to this select-waiter (the single claim). */
        public bool $selectClaimed = false;
        /** The channel that actually completed — identifies the winning case. */
        public ?Channel $chanReady = null;
        /** True when the completed case was a SEND. */
        public bool $chanSend = false;
        /** The single channel this task is parked on, for deregistration on cancel. */
        public ?Channel $chanHost = null;
        /** @var Channel[] every channel a select() parked this task on */
        public array $selectChans = [];

        /** Position in owner->children, or -1 once pruned (O(1) removal). */
        public int $idx = -1;

        /** Monotonic id, and an optional label from {@see named()} — diagnostics only. */
        public int $id = 0;
        public string $name = '';

        /**
         * `file:line` of the spawn that created this task — filled in by the
         * COMPILER, which rewrites `Async\spawn(…)` to the internal `…At` form
         * carrying a folded literal (the same place `__FILE__` is folded, since
         * lowering sees a statement list flattened across every file).
         *
         * It is the difference between `#3 io-read fd=7` and a line of code when
         * a program hangs, and nobody remembers to call {@see named()} BEFORE the
         * hang. '' when the spawn came from somewhere the rewrite does not reach
         * (a dynamic callable, a spawn inside the prelude itself).
         */
        public string $origin = '';

        /**
         * Intrusive doubly-linked list of LIVE tasks, head on the Scheduler. It
         * exists so {@see Async\dump()} can enumerate what is running when a program
         * hangs: the scope tree cannot answer that (settled children are pruned, and
         * a nested group's children hang off the group, not the root). O(1) link on
         * spawn, O(1) unlink on settle, no array to grow.
         */
        public ?Task $allNext = null;
        public ?Task $allPrev = null;

        /** Label this task for diagnostics: `spawn(…)->named('worker-3')`. */
        public function named(string $name): Task
        {
            $this->name = $name;
            return $this;
        }

        /**
         * Longest stretch this task has held the loop, in seconds — the watchdog's
         * rate limit: report the first breach, then only a doubling, so a genuinely
         * CPU-bound worker cannot turn the log into its own bottleneck.
         */
        public float $wdWorst = 0.0;

        /** Just the identity — `#id "label" at file:line`, no state. */
        public function label(): string
        {
            $name = $this->name === '' ? '' : (' "' . $this->name . '"');
            // `near …` is already a preposition — `at near x` reads as a typo.
            $where = '';
            if ($this->origin !== '') {
                $where = \substr($this->origin, 0, 5) === 'near '
                    ? (' ' . $this->origin) : (' at ' . $this->origin);
            }
            return '#' . (string)$this->id . $name . $where;
        }

        /** One line for the diagnostic report: id, label, state, what it waits on. */
        public function describe(): string
        {
            $what = 'ready';
            if ($this->state === self::DONE) {
                $what = 'done';
            } elseif ($this->state === self::FAILED) {
                $what = 'failed(' . ($this->error === null ? '?' : \get_class($this->error)) . ')';
            } elseif ($this->ioFd >= 0) {
                $what = ($this->ioWrite ? 'io-write fd=' : 'io-read fd=') . (string)$this->ioFd
                      . ($this->timerActive ? ' +deadline' : '');
            } elseif ($this->selFds > 0) {
                $what = 'select(' . (string)$this->selFds . ' fds)'
                      . ($this->timerActive ? ' +deadline' : '');
            } elseif ($this->chanHost !== null) {
                $what = 'channel';
            } elseif ($this->selecting) {
                $what = 'select(' . (string)\count($this->selectChans) . ' channels)';
            } elseif ($this->timerActive) {
                $what = 'timer';
            }
            $flags = '';
            if ($this->daemon) { $flags = $flags . ' daemon'; }
            if ($this->cancelRequested) { $flags = $flags . ' cancel-requested'; }
            if ($this->shield > 0) { $flags = $flags . ' shielded'; }
            // `awaited` (someone is handling its outcome), not `unclaimed` — every
            // freshly spawned task is unclaimed, so that spelling was pure noise.
            if ($this->claimed) { $flags = $flags . ' awaited'; }
            return $this->label() . ' ' . $what . $flags;
        }

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

        /**
         * Ask this task to stop. It is deregistered from whatever it waits on and
         * raises CancelledException at its suspend point — and at every one after
         * that. Returns immediately; use {@see await()} to see it out.
         */
        public function cancel(): void
        {
            Scheduler::instance()->cancelTask($this);
        }

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
         * Wait for this task and report HOW it ended, without rethrowing. What
         * you want after cancelling one yourself: `$t->cancel(); $t->join();`
         * reads as "stop it and see it out", whereas `await()` would raise the
         * task's own CancelledException into a caller that is not being
         * cancelled at all — and that would unwind the caller's scope.
         */
        public function join(): Settled
        {
            $sched = Scheduler::instance();
            $this->claimed = true;
            while ($this->state === self::PENDING) {
                $this->addWaiter($sched->current());
                $sched->suspendCurrent();
            }
            return $this->state === self::DONE
                ? new Settled(true, $this->result, null)
                : new Settled(false, null, $this->error);
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
                $sched->shieldedJoinTask($this);
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
     *   Async\group(function (TaskGroup $g) {
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

        /** @var array<string, mixed> scoped values, inherited down the chain */
        public array $values = [];

        /**
         * `file:line` where this scope was opened, folded in by the compiler
         * ({@see Task::$origin}). It is what gives `$g->spawn(…)` a location: the
         * receiver's class is not known until InferTypes, so that call cannot be
         * rewritten at lowering, and the scope it spawns into is the next best
         * answer — reported as `near <site>` so it is never mistaken for the exact
         * spawn line.
         */
        public string $site = '';

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

        /** True when $t is owned by this scope or one nested inside it. */
        public function owns(Task $t): bool
        {
            $g = $t->owner;
            while ($g !== null) {
                if ($g === $this) { return true; }
                $g = $g->parent;
            }
            return false;
        }

        /**
         * Open a scope, run $body($group), then join every child before returning.
         * {@see group()} is the everyday spelling of this.
         */
        public static function run(callable $body): mixed
        {
            return self::runAt('', $body);
        }

        /** {@see run()} with the compiler-folded call site. @internal */
        public static function runAt(string $site, callable $body): mixed
        {
            $sched = Scheduler::instance();
            $cur = $sched->current();
            $group = new TaskGroup($cur->scope);
            $group->site = $site;
            $cur->scope = $group;
            $result = null;
            $thrown = null;
            try {
                $result = $body($group);
            } catch (\Throwable $e) {
                // The body blew up — the children it already spawned are NOT
                // orphaned: record, cancel, and still join below.
                $thrown = $e;
                $group->fail($e);
            }
            try {
                $group->joinAll();
            } catch (\Throwable $e) {
                // We were cancelled while reaping. Tell the children to stop and
                // finish the reap SHIELDED — they were just cancelled, so it is
                // bounded, and a half-joined scope would leak tasks.
                if ($thrown === null) { $thrown = $e; }
                $group->cancel();
                $sched->shieldedJoin($group);
            } finally {
                $cur->scope = $group->parent;
            }
            // Cancellation is never swallowed, whatever fail() decided to record.
            if ($thrown instanceof CancelledException) { throw $thrown; }
            if ($group->failure !== null) { throw $group->failure; }
            if ($thrown !== null) { throw $thrown; }
            return $result;
        }

        /** Spawn a child task into this group. Returns a handle to await. */
        public function spawn(callable $fn, mixed ...$args): Task
        {
            return $this->spawnAt('', $fn, ...$args);
        }

        /**
         * {@see spawn()} with the call site the compiler folded in — see
         * {@see Task::$origin}. `@internal`: written by the lowering rewrite, not
         * by hand (`$g->spawn(…)` is the API and picks this up automatically when
         * the receiver is statically a TaskGroup).
         */
        public function spawnAt(string $site, callable $fn, mixed ...$args): Task
        {
            $task = Scheduler::instance()->newTask($fn, $args, $this);
            // No exact site (`$g->spawn(…)`, a dynamic callable) ⇒ fall back to the
            // scope's own line, flagged as approximate.
            if ($site === '' && $this->site !== '') { $site = 'near ' . $this->site; }
            $task->origin = $site;
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
                // Our own cancellation coming back — not a new failure. The
                // unwinding is preserved by run(), which rethrows it regardless.
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
         * whatever it waits on and raises CancelledException at its suspend
         * point; a child that itself opened scopes unwinds through their joins,
         * so cancellation is recursive by construction.
         */
        public function cancel(): void
        {
            if ($this->cancelled) { return; }
            $this->cancelled = true;
            // Handlers first: they release things the scheduler cannot see, and a
            // cancelled child may go on to touch them. A handler that throws must
            // not unwind cancel() — it is reached from inside the scheduler loop
            // (settle → fail → cancel) — so it is recorded, not propagated.
            $handlers = $this->cancelHandlers;
            $this->cancelHandlers = [];
            foreach ($handlers as $fn) {
                try {
                    $fn();
                } catch (\Throwable $e) {
                    if ($this->failure === null) { $this->failure = $e; }
                }
            }
            $sched = Scheduler::instance();
            foreach ($this->children as $c) {
                $sched->cancelTask($c);
            }
        }
    }

    /**
     * A CSP channel over the cooperative scheduler. Unbuffered (cap 0) is a
     * rendezvous; buffered parks only when full (send) / empty (recv).
     * close() wakes everyone: pending recv reports !ok, pending/future send throws.
     *
     * Consumption is a `foreach` — the channel is the iterable, and the loop ends
     * when it is closed and drained:
     *
     *   foreach ($ch as $value) { … }
     *
     * {@see next()} is the explicit form when you need to tell "closed" from a
     * legitimate null payload; {@see recv()} is the terse form that cannot.
     */
    final class Channel implements \IteratorAggregate
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

        public function getIterator(): \Iterator
        {
            return new ChannelIterator($this);
        }

        /** Send $value, suspending until it is buffered or taken by a receiver. */
        public function send(mixed $value): void
        {
            if ($this->closed) {
                throw new ChannelClosedException('send on a closed channel');
            }
            $sched = Scheduler::instance();

            if ($this->handOff($value)) {
                return;
            }
            if (\count($this->buffer) < $this->cap) {
                $this->buffer[] = $value;
                return;
            }

            // Park until a receiver takes this exact value. checkCancel BEFORE
            // registering: throwing after would strand us in sendQ.
            $sched->checkCancel();
            $me = $sched->current();
            $this->sendQ[] = $me;
            $this->sendVal[] = $value;
            $me->chanHost = $this;
            \Fiber::suspend();
            $me->chanHost = null;

            if ($this->closed) {
                throw new ChannelClosedException('send on a closed channel');
            }
        }

        /** Hand $value to a parked receiver, if there is a live one. */
        private function handOff(mixed $value): bool
        {
            while (\count($this->recvQ) > 0) {
                $r = \array_shift($this->recvQ);
                if ($this->wakeReceiver($r, $value, true)) {
                    return true;
                }
            }
            return false;
        }

        /**
         * The next delivery, as an object. `ok` is false once the channel is
         * closed and drained — the only honest way to report that when null is a
         * legal payload.
         */
        public function next(): Received
        {
            $sched = Scheduler::instance();

            if (\count($this->buffer) > 0) {
                $v = \array_shift($this->buffer);
                $this->promoteSender();
                return new Received($v, true);
            }

            $taken = $this->takeFromSender();
            if ($taken !== null) {
                return $taken;
            }

            if ($this->closed) {
                return new Received(null, false);
            }

            $sched->checkCancel();
            $me = $sched->current();
            $me->chanValue = null;
            $me->chanOk = true;
            $this->recvQ[] = $me;
            $me->chanHost = $this;
            \Fiber::suspend();
            $me->chanHost = null;
            return new Received($me->chanValue, $me->chanOk);
        }

        /**
         * The terse receive: the value, or null once the channel is closed and
         * drained. Convenient, but it cannot carry a legitimate null payload —
         * use {@see next()} or `foreach` when null is a real value.
         */
        public function recv(): mixed
        {
            return $this->next()->value;
        }

        /** Take from a parked sender (unbuffered rendezvous), skipping dead ones. */
        private function takeFromSender(): ?Received
        {
            while (\count($this->sendQ) > 0) {
                $s = \array_shift($this->sendQ);
                $v = \array_shift($this->sendVal);
                if (!$this->claimSender($s)) {
                    continue;   // cancelled or already claimed by another case
                }
                return new Received($v, true);
            }
            return null;
        }

        /**
         * Commit to delivering ($value, $ok) to a parked receiver under the
         * single-claim rule that makes {@see select()} sound: a waiter parked on
         * several channels is won by the FIRST channel to reach it. Returns false
         * when the entry is stale — already claimed, settled, or cancelled — so
         * the caller discards it and tries the next one.
         */
        private function wakeReceiver(Task $r, mixed $value, bool $ok): bool
        {
            if ($r->state !== Task::PENDING || $r->cancelRequested) {
                return false;
            }
            if ($r->selecting) {
                if ($r->selectClaimed) { return false; }
                $r->selectClaimed = true;
            }
            $r->chanValue = $value;
            $r->chanOk = $ok;
            $r->chanReady = $this;
            $r->chanSend = false;
            $r->chanHost = null;
            Scheduler::instance()->wake($r);
            return true;
        }

        /** The send-side mirror of {@see wakeReceiver} — claim a parked sender. */
        private function claimSender(Task $s): bool
        {
            if ($s->state !== Task::PENDING || $s->cancelRequested) {
                return false;
            }
            if ($s->selecting) {
                if ($s->selectClaimed) { return false; }
                $s->selectClaimed = true;
            }
            $s->chanReady = $this;
            $s->chanSend = true;
            $s->chanHost = null;
            Scheduler::instance()->wake($s);
            return true;
        }

        // ── select() support ────────────────────────────────────────────────

        /** Non-blocking receive attempt for the select() fast path. */
        public function trySelectRecv(): ?Received
        {
            if (\count($this->buffer) > 0) {
                $v = \array_shift($this->buffer);
                $this->promoteSender();
                return new Received($v, true);
            }
            $taken = $this->takeFromSender();
            if ($taken !== null) {
                return $taken;
            }
            if ($this->closed) {
                return new Received(null, false);
            }
            return null;
        }

        /** Non-blocking send attempt for the select() fast path. */
        public function trySelectSend(mixed $value): bool
        {
            if ($this->closed) {
                throw new ChannelClosedException('send on a closed channel');
            }
            if ($this->handOff($value)) {
                return true;
            }
            if (\count($this->buffer) < $this->cap) {
                $this->buffer[] = $value;
                return true;
            }
            return false;
        }

        /** Park $t on this channel's receive queue (select() slow path). */
        public function registerSelectRecv(Task $t): void
        {
            $this->recvQ[] = $t;
        }

        /** Park $t on this channel's send queue offering $value (select() slow path). */
        public function registerSelectSend(Task $t, mixed $value): void
        {
            $this->sendQ[] = $t;
            $this->sendVal[] = $value;
        }

        /**
         * Remove a parked task from BOTH queues by identity — a select loser, or
         * a task the scheduler just cancelled. The sendQ/sendVal pair is rebuilt
         * in lockstep (array_splice is still unimplemented).
         */
        public function removeWaiter(Task $t): void
        {
            /** @var Task[] $keptR */
            $keptR = [];
            foreach ($this->recvQ as $r) {
                if ($r !== $t) { $keptR[] = $r; }
            }
            $this->recvQ = $keptR;

            /** @var Task[] $keptS */
            $keptS = [];
            /** @var mixed[] $keptV */
            $keptV = [];
            $n = \count($this->sendQ);
            for ($i = 0; $i < $n; $i++) {
                if ($this->sendQ[$i] !== $t) {
                    $keptS[] = $this->sendQ[$i];
                    $keptV[] = $this->sendVal[$i];
                }
            }
            $this->sendQ = $keptS;
            $this->sendVal = $keptV;
        }

        /** Move one parked sender's value into a buffer slot freed by a recv. */
        private function promoteSender(): void
        {
            while (\count($this->sendQ) > 0 && \count($this->buffer) < $this->cap) {
                $s = \array_shift($this->sendQ);
                $v = \array_shift($this->sendVal);
                if (!$this->claimSender($s)) { continue; }
                $this->buffer[] = $v;
                return;
            }
        }

        /** Close: parked receivers report !ok, parked/future senders throw. */
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

            // Wake parked senders; each re-checks $closed on resume and throws.
            foreach ($this->sendQ as $s) {
                $s->chanHost = null;
                $sched->wake($s);
            }
            $this->sendQ = [];
            $this->sendVal = [];
        }
    }

    /**
     * `foreach ($channel as $value)`. A plain Iterator rather than a generator:
     * each call is an ordinary frame on the fiber's own stack, so parking inside
     * it is just a fiber suspend with no generator machinery in between.
     */
    final class ChannelIterator implements \Iterator
    {
        private mixed $cur = null;
        private bool $ok = false;
        private int $i = -1;

        public function __construct(private Channel $ch) {}

        public function rewind(): void { $this->i = -1; $this->fetch(); }
        public function valid(): bool { return $this->ok; }
        public function current(): mixed { return $this->cur; }
        public function key(): mixed { return $this->i; }
        public function next(): void { $this->fetch(); }

        private function fetch(): void
        {
            $r = $this->ch->next();
            $this->ok = $r->ok;
            $this->cur = $r->value;
            if ($r->ok) { $this->i = $this->i + 1; }
        }
    }

    /**
     * A counting semaphore — the bound on "do N of these at a time". Parks the
     * task rather than the process, so a limiter costs nothing while it waits.
     *
     *   $sem = new Async\Semaphore(10);
     *   $sem->withPermit(fn() => fetch($url));
     *
     * {@see mapConcurrent()} is the batteries-included form.
     */
    final class Semaphore
    {
        private int $free;
        /** @var Task[] tasks waiting for a permit, FIFO */
        private array $waitQ = [];

        public function __construct(int $permits)
        {
            $this->free = $permits < 1 ? 1 : $permits;
        }

        /** Permits currently available. */
        public function available(): int
        {
            return $this->free;
        }

        public function acquire(): void
        {
            $sched = Scheduler::instance();
            while ($this->free === 0) {
                // A wake is a hint: another task may have taken the permit first,
                // so re-check rather than assume.
                $sched->checkCancel();
                $this->waitQ[] = $sched->current();
                \Fiber::suspend();
            }
            $this->free = $this->free - 1;
        }

        public function release(): void
        {
            $this->free = $this->free + 1;
            // Skip entries whose task died or was cancelled while queued.
            while (\count($this->waitQ) > 0) {
                $t = \array_shift($this->waitQ);
                if ($t->state === Task::PENDING && !$t->cancelRequested) {
                    Scheduler::instance()->wake($t);
                    return;
                }
            }
        }

        /** Hold a permit for the duration of $fn, releasing it however $fn ends. */
        public function withPermit(callable $fn): mixed
        {
            $this->acquire();
            try {
                return $fn();
            } finally {
                $this->release();
            }
        }
    }

    /**
     * A mutual-exclusion lock for a critical section that SPANS A SUSPEND POINT.
     *
     * ⚠ Read this before reaching for one. The scheduler is cooperative and
     * single-threaded: nothing else runs between two suspend points, so an
     * ordinary read-modify-write —
     *
     *     $shared->n = $shared->n + 1;
     *
     * — is already atomic by construction. There is no data race to protect
     * against, and therefore no need for atomics in this runtime at all. (They
     * would only start to mean something under real shared-memory threading,
     * which is a separate epic; the forked `workers()` are shared-nothing.)
     *
     * What a Mutex protects is INTERLEAVING: a section that suspends in the
     * middle, so other tasks get to run and can observe or mutate half-updated
     * state.
     *
     *     $mu->withLock(function () use ($store) {
     *         $a = fetch();          // suspends — another task runs here
     *         $b = fetch();
     *         $store->write($a, $b); // must not interleave with another writer
     *     });
     *
     * Not reentrant: locking one you already hold is a deadlock, so it is a
     * LogicException instead. FIFO, so no waiter starves.
     */
    final class Mutex
    {
        private bool $held = false;
        private ?Task $owner = null;
        /** @var Task[] tasks waiting for the lock, FIFO */
        private array $waitQ = [];

        public function isLocked(): bool { return $this->held; }

        public function lock(): void
        {
            $sched = Scheduler::instance();
            $me = $sched->current();
            if ($this->held && $this->owner === $me) {
                throw new \LogicException('Async\\Mutex is not reentrant');
            }
            while ($this->held) {
                // A wake is a hint: another waiter may have taken the lock first.
                $sched->checkCancel();
                $this->waitQ[] = $me;
                \Fiber::suspend();
            }
            $this->held = true;
            $this->owner = $me;
        }

        /** Take the lock only if it is free. Never suspends. */
        public function tryLock(): bool
        {
            if ($this->held) { return false; }
            $this->held = true;
            $this->owner = Scheduler::instance()->current();
            return true;
        }

        public function unlock(): void
        {
            if (!$this->held) {
                throw new \LogicException('unlock() on a mutex that is not held');
            }
            $this->held = false;
            $this->owner = null;
            // Skip entries whose task died or was cancelled while queued.
            while (\count($this->waitQ) > 0) {
                $t = \array_shift($this->waitQ);
                if ($t->state === Task::PENDING && !$t->cancelRequested) {
                    Scheduler::instance()->wake($t);
                    return;
                }
            }
        }

        /** Hold the lock for the duration of $fn, releasing it however $fn ends. */
        public function withLock(callable $fn): mixed
        {
            $this->lock();
            try {
                return $fn();
            } finally {
                $this->unlock();
            }
        }
    }

    /**
     * Run something exactly once, however many tasks ask for it — the lazy
     * initialiser (a connection pool, a parsed config). Needed because the work
     * suspends: without it two tasks both see "not ready yet" and both build.
     * Everyone gets the same value, or the same error.
     *
     *   $pool = $once->run(fn() => buildPool());
     *
     * A CANCELLED initialiser is not a result: the state resets so the next
     * caller can try again instead of inheriting someone else's cancellation.
     */
    final class Once
    {
        private const FRESH = 0;
        private const RUNNING = 1;
        private const DONE = 2;

        private int $state = self::FRESH;
        private mixed $result = null;
        private ?\Throwable $error = null;
        /** @var Task[] tasks waiting for the in-flight initialiser */
        private array $waitQ = [];

        public function hasRun(): bool { return $this->state === self::DONE; }

        public function run(callable $fn): mixed
        {
            $sched = Scheduler::instance();
            while ($this->state === self::RUNNING) {
                $sched->checkCancel();
                $this->waitQ[] = $sched->current();
                \Fiber::suspend();
            }
            if ($this->state === self::DONE) {
                if ($this->error !== null) { throw $this->error; }
                return $this->result;
            }

            $this->state = self::RUNNING;
            $failed = null;
            try {
                $this->result = $fn();
            } catch (\Throwable $e) {
                $failed = $e;
            }
            if ($failed instanceof CancelledException) {
                $this->state = self::FRESH;
                $this->wakeWaiters();
                throw $failed;
            }
            $this->error = $failed;
            $this->state = self::DONE;
            $this->wakeWaiters();
            if ($this->error !== null) { throw $this->error; }
            return $this->result;
        }

        private function wakeWaiters(): void
        {
            $q = $this->waitQ;
            $this->waitQ = [];
            $sched = Scheduler::instance();
            foreach ($q as $t) { $sched->wake($t); }
        }
    }

    /**
     * The ambient view of the scope the calling task is in: cancellation, the
     * effective deadline, and scoped values.
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

        /**
         * A value bound by an enclosing {@see withValue()} scope, or null. The
         * request-id / correlation-id carrier: every task spawned inside the
         * binding scope sees it, nothing outside does.
         */
        public static function value(string $key): mixed
        {
            $g = self::currentScope();
            while ($g !== null) {
                if (\array_key_exists($key, $g->values)) { return $g->values[$key]; }
                $g = $g->parent;
            }
            return null;
        }

        /** Bind $key for the duration of $body — a scope, so it is joined like any other. */
        public static function withValue(string $key, mixed $value, callable $body): mixed
        {
            return TaskGroup::run(function (TaskGroup $g) use ($key, $value, $body) {
                $g->values[$key] = $value;
                return $body($g);
            });
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

        /** The implicit root scope — what {@see cancelRoot()} stops. */
        private ?TaskGroup $root = null;
        /** True once the signal pump daemon task exists ({@see pollSignals()}). */
        private bool $signalPolling = false;

        private \Io\Poll\Context $reactor;
        /** @var array<int, \Io\Poll\Watcher> fd → its PERSISTENT reactor watcher */
        private array $connWatcher = [];
        /** @var array<int, Task> fd → the task parked on readability */
        private array $readWaiter = [];
        /** @var array<int, Task> fd → the task parked on writability */
        private array $writeWaiter = [];
        /** @var array<int, bool> fd → is Write currently armed on its watcher */
        private array $writeArmed = [];
        /** @var array<int, SelectReg> fd → head of the select-interest chain */
        private array $selWaiter = [];
        /** Parked-on-I/O task count (NOT registered-fd count: an idle fd is not work). */
        private int $ioWaiters = 0;

        /** @var float[] binary min-heap of deadlines, parallel to tmTask */
        private array $tmDeadline = [];
        /** @var Task[] the task each heap slot belongs to */
        private array $tmTask = [];
        /** Live (non-cancelled) timer count — cancelled slots are deleted lazily. */
        private int $tmLive = 0;

        /** Head of the live-task list + the id counter, both for {@see report()}. */
        private ?Task $taskHead = null;
        private int $nextTaskId = 0;

        /**
         * Loop-hog watchdog: how long one task may hold the loop, in SECONDS.
         * 0.0 = off, and off costs one float compare per resume — {@see step()} is
         * the 150k-rps path.
         *
         * A cooperative loop cannot preempt, so this reports AFTER the fact — which
         * is the whole point: the failure mode it catches (regular-file I/O, a
         * CPU-bound stretch with no suspend point, a blocking fallback) is invisible
         * otherwise. Every other task simply stops for that long.
         *
         * Set by `MANTICORE_ASYNC_WATCHDOG` (milliseconds) or {@see watchdog()}.
         */
        private float $watchdog = 0.0;

        /** @var int[] monotonic engine counters, exposed by {@see Async\stats()} */
        private int $nSpawned = 0;
        private int $nSettled = 0;
        private int $nCancelled = 0;
        private int $nWakes = 0;
        private int $nReactorWaits = 0;
        private int $nTimerFires = 0;
        private int $nWatchdog = 0;

        /**
         * Resolver cache, host → address and host → expiry (two parallel assocs, so
         * neither holds a nested array). It lives HERE because the stdlib cannot own
         * an assoc in a static, and because a scheduler run is exactly the lifetime a
         * per-program DNS cache wants: `async()` drops the singleton on the way out,
         * so nothing survives into the next run to go stale.
         * @var array<string,string> $dnsIp
         * @var array<string,float>  $dnsExp
         */
        private array $dnsIp = [];
        private array $dnsExp = [];

        private function __construct()
        {
            $this->reactor = new \Io\Poll\Context(\Io\Poll\Backend::Auto);
            // getenv is `string|false` (a cell) — unbox before comparing.
            $ms = \getenv('MANTICORE_ASYNC_WATCHDOG');
            if ($ms !== false && $ms !== '') {
                $this->setWatchdog((float)(string)$ms);
            }
        }

        /** Threshold in MILLISECONDS; <= 0 turns it off. {@see $watchdog} */
        public function setWatchdog(float $milliseconds): void
        {
            $this->watchdog = $milliseconds > 0.0 ? $milliseconds / 1000.0 : 0.0;
        }

        public static function instance(): Scheduler
        {
            if (self::$instance === null) {
                self::$instance = new Scheduler();
            }
            return self::$instance;
        }

        /** Whether a loop is up — {@see Async\dump()} must not CREATE one. */
        public static function hasInstance(): bool
        {
            return self::$instance !== null;
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
        public function newTask(callable $fn, array $args, TaskGroup $owner, bool $daemon = false): Task
        {
            $fiber = new \Fiber(function () use ($fn, $args) {
                return $fn(...$args);
            });
            $task = new Task($fiber, $owner, $owner);
            $task->daemon = $daemon;
            $this->nextTaskId = $this->nextTaskId + 1;
            $task->id = $this->nextTaskId;
            // Link into the live-task list (head insert) so dump() can enumerate.
            $task->allNext = $this->taskHead;
            if ($this->taskHead !== null) { $this->taskHead->allPrev = $task; }
            $this->taskHead = $task;
            if (!$daemon) { $this->live = $this->live + 1; }
            $this->nSpawned = $this->nSpawned + 1;
            $task->queued = true;
            $this->ready[] = $task;
            return $task;
        }

        /**
         * Cached address for $host, or '' when absent or expired. Called from the
         * stdlib resolver through the netpoller hook.
         */
        public function dnsLookup(string $host): string
        {
            if (!isset($this->dnsIp[$host])) {
                return '';
            }
            $exp = $this->dnsExp[$host] ?? 0.0;
            if ($exp <= \microtime(true)) {
                unset($this->dnsIp[$host]);
                unset($this->dnsExp[$host]);
                return '';
            }
            return $this->dnsIp[$host];
        }

        /**
         * Remember $host → $ip for $ttl seconds. The TTL is the record's own, capped
         * at 5 minutes: a long TTL on a value we cannot invalidate would outlive a
         * real deployment change, and a resolver cache is a latency optimisation, not
         * a source of truth.
         */
        public function dnsStore(string $host, string $ip, int $ttl): void
        {
            if ($host === '' || $ip === '') {
                return;
            }
            $secs = $ttl > 300 ? 300 : $ttl;
            $this->dnsIp[$host] = $ip;
            $this->dnsExp[$host] = \microtime(true) + (float)$secs;
        }

        /**
         * A human-readable snapshot of the engine: every live task with what it is
         * parked on, plus the reactor/timer counters. This is the answer to "the
         * program hangs and I have no idea why" — print it from a signal handler, or
         * read it off a DeadlockException, which embeds it.
         */
        public function report(): string
        {
            $out = 'async: ' . (string)$this->live . ' live task(s), '
                 . (string)$this->ioWaiters . ' parked on I/O, '
                 . (string)$this->tmLive . ' on timers, '
                 . (string)\count($this->ready) . " ready\n";
            $t = $this->taskHead;
            if ($t === null) {
                return $out . "  (no live tasks)\n";
            }
            while ($t !== null) {
                $cur = $t === $this->running ? '* ' : '  ';
                $out = $out . $cur . $t->describe() . "\n";
                $t = $t->allNext;
            }
            return $out;
        }

        // ── the run entry ──────────────────────────────────────────────────
        public function run(callable $main, string $site = ''): mixed
        {
            $root = new TaskGroup(null);
            $this->root = $root;
            $rootTask = $this->newTask($main, [], $root);
            $rootTask->origin = $site;
            $rootTask->idx = 0;
            $root->children[] = $rootTask;
            $this->installNetpoller();
            $this->loop();
            $this->clearNetpoller();

            $stuck = $this->live > 0;
            $failure = $root->failure;
            $result = $rootTask->result;
            // A CancelledException that reached the top WITHOUT the root actually
            // being cancelled did not come from a shutdown — it leaked out of some
            // await() and would otherwise end the program silently with rc=0.
            // Deliberate shutdown ({@see shutdownOn()}) sets cancelRequested, and
            // that path still returns normally.
            if ($failure === null && $rootTask->state === Task::FAILED
                && !$rootTask->cancelRequested) {
                $failure = $rootTask->error;
            }
            // The deadlock report must be taken BEFORE the singleton is dropped —
            // "all tasks are asleep" with no further detail is useless when a real
            // program hangs, so the message carries the task table.
            $stuckReport = $stuck ? $this->report() : '';
            // Dropping the singleton drops the reactor (Io\Poll\Context::__destruct
            // closes its kqueue/epoll fd) and every queue — the next async() in the
            // same program starts from a clean engine.
            self::$instance = null;
            if ($failure !== null) {
                throw $failure;
            }
            if ($stuck) {
                throw new DeadlockException(
                    "async: every task is asleep and nothing can wake them — deadlock\n"
                    . $stuckReport);
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
                function (\Resource $s, float $t): bool { return $this->waitReadableWithin($s, $t); },
                function (\Resource $s, float $t): bool { return $this->waitWritableWithin($s, $t); },
                function (float $t): void { $this->sleep($t); },
                function (string $host): string { return $this->dnsLookup($host); },
                function (string $host, string $ip, int $ttl): void { $this->dnsStore($host, $ip, $ttl); },
            );
            \Runtime\AsyncHook::installSelect(
                function (int $fd, bool $write): void { $this->selectAdd($fd, $write); },
                function (float $t): bool { return $this->selectWait($t); },
                function (): void { $this->selectDone(); },
            );
        }

        private function clearNetpoller(): void
        {
            \Runtime\AsyncHook::clear();
        }

        /**
         * Stop the whole program's root scope — graceful shutdown. Everything
         * unwinds through the machinery already there: the accept loop and every
         * live connection get CancelledException at their suspend point, scopes
         * join their children, and shield() covers a final flush. A root
         * cancellation is not a failure, so async() then returns normally.
         */
        public function cancelRoot(): void
        {
            if ($this->root !== null) {
                $this->root->cancel();
            }
        }

        /**
         * Start pumping signals. Called by the loop itself the moment any handler
         * is registered, so plain `pcntl_signal(SIGHUP, …)` just works inside
         * async() — there is no async-specific way to register one.
         *
         * The dispatch runs inside a DAEMON TASK in the root scope, not at the
         * loop level: a handler running between tasks would have no scope, so it
         * could not spawn(), and `$this->running` would be null under it. As a
         * task it is ordinary async code — it can spawn, suspend and be cancelled
         * with everything else at shutdown — and its own delay() bounds the
         * latency, so the reactor needs to know nothing about signals.
         */
        private function pollSignals(): void
        {
            if ($this->signalPolling || $this->root === null) {
                return;
            }
            $this->signalPolling = true;
            $pump = $this->newTask(function () {
                $sched = Scheduler::instance();
                while (true) {
                    \pcntl_signal_dispatch();
                    $sched->sleep(0.05);
                }
            }, [], $this->root, true);
            $pump->claimed = true;      // it only ever ends by cancellation
            $pump->idx = \count($this->root->children);
            $this->root->children[] = $pump;
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
                // Someone registered a signal handler (through plain
                // pcntl_signal, from anywhere) — start the pump. Checked HERE,
                // immediately before the blocking wait, and not at the top of the
                // loop: a server parked in accept() reaches the top again only
                // once the reactor returns, so an idle worker would have started
                // no pump and never seen its SIGTERM. The pump lands in the ready
                // queue, so `continue` runs it and its delay() then bounds the
                // wait below.
                if (!$this->signalPolling && \Runtime\Signals::instance()->any()) {
                    $this->pollSignals();
                    continue;
                }
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
            // One float compare when the watchdog is off; microtime only when it is on.
            $t0 = $this->watchdog > 0.0 ? \microtime(true) : 0.0;
            try {
                if (!$task->fiber->isStarted()) {
                    $task->fiber->start();
                } elseif ($task->cancelRequested && $task->shield === 0) {
                    // STICKY: every resume of a cancelled task raises again, so a
                    // blanket catch buys it exactly one more suspend point.
                    $task->fiber->throw(new CancelledException('task cancelled'));
                } else {
                    $task->fiber->resume();
                }
            } catch (\Throwable $e) {
                $this->running = $prev;
                if ($t0 > 0.0) { $this->watchdogCheck($task, $t0); }
                $this->settle($task, Task::FAILED, null, $e);
                $task->fiber->reclaim();      // terminated via exception → free stack now
                return;
            }
            $this->running = $prev;
            if ($t0 > 0.0) { $this->watchdogCheck($task, $t0); }
            if ($task->fiber->isTerminated()) {
                $this->settle($task, Task::DONE, $task->fiber->getReturn(), null);
                $task->fiber->reclaim();      // free+pool the stack now, not at __destruct
            }
        }

        /**
         * Did that resume hold the loop too long? Reported after the fact, on
         * STDERR, naming the task and where it was spawned — the answer to "why
         * did everything else stall", which is otherwise invisible.
         */
        private function watchdogCheck(Task $task, float $t0): void
        {
            $held = \microtime(true) - $t0;
            if ($held < $this->watchdog) { return; }
            // First breach, then only a doubling: see Task::$wdWorst.
            if ($task->wdWorst > 0.0 && $held < $task->wdWorst * 2.0) { return; }
            $task->wdWorst = $held;
            $this->nWatchdog = $this->nWatchdog + 1;
            \fwrite(\STDERR, 'async: watchdog — task ' . $task->label() . ' held the loop '
                . __ms($held) . ' ms (limit ' . __ms($this->watchdog) . " ms)\n");
        }

        /** @return array<string,int> monotonic counters + the current gauges */
        public function stats(): array
        {
            $out = [];
            $out['spawned'] = $this->nSpawned;
            $out['settled'] = $this->nSettled;
            $out['cancelled'] = $this->nCancelled;
            $out['wakes'] = $this->nWakes;
            $out['reactor_waits'] = $this->nReactorWaits;
            $out['timer_fires'] = $this->nTimerFires;
            $out['watchdog'] = $this->nWatchdog;
            $out['live'] = $this->live;
            $out['ready'] = \count($this->ready);
            $out['io_parked'] = $this->ioWaiters;
            $out['timers'] = $this->tmLive;
            return $out;
        }

        private function settle(Task $task, int $state, mixed $result, ?\Throwable $error): void
        {
            $task->state = $state;
            $task->result = $result;
            $task->error = $error;
            $this->nSettled = $this->nSettled + 1;
            if (!$task->daemon) { $this->live = $this->live - 1; }
            // Unlink from the live-task list (diagnostics) — O(1), both directions.
            if ($task->allPrev !== null) {
                $task->allPrev->allNext = $task->allNext;
            } elseif ($this->taskHead === $task) {
                $this->taskHead = $task->allNext;
            }
            if ($task->allNext !== null) {
                $task->allNext->allPrev = $task->allPrev;
            }
            $task->allNext = null;
            $task->allPrev = null;
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
         * Raise a requested cancellation BEFORE the caller registers itself
         * anywhere. Every park site calls this first, then registers, then
         * suspends — registering and only then throwing would strand a live
         * reactor slot, timer reservation or channel-queue entry on the way out.
         * Sticky: it keeps raising until the task terminates (or shields).
         */
        public function checkCancel(): void
        {
            $me = $this->running;
            if ($me !== null && $me->cancelRequested && $me->shield === 0) {
                throw new CancelledException('task cancelled');
            }
        }

        public function wake(Task $task): void
        {
            if ($task->state === Task::PENDING && !$task->queued) {
                $task->queued = true;
                $this->nWakes = $this->nWakes + 1;
                $this->ready[] = $task;
            }
        }

        /**
         * Cancel $task. A parked task is deregistered from everything it waits on
         * (reactor slot, timer, channel queues) and resumed into a
         * CancelledException; the RUNNING task just gets the flag and hits it at
         * its next suspend point.
         */
        public function cancelTask(Task $task): void
        {
            if ($task->state !== Task::PENDING) { return; }
            if ($task->cancelRequested) { return; }
            $task->cancelRequested = true;
            $this->nCancelled = $this->nCancelled + 1;
            if ($task === $this->running) { return; }
            $this->releaseIo($task);
            if ($task->timerActive) {
                $task->timerActive = false;
                if (!$task->daemon) { $this->tmLive = $this->tmLive - 1; }
            }
            $this->releaseChannel($task);
            // A cancelled select must drop its per-fd records too, or the reactor
            // keeps a path to a task that has left and the write arm never lowers.
            if ($task->selFds > 0) {
                $this->ioWaiters = $this->ioWaiters - 1;
                $this->releaseSelect($task);
            }
            $task->selecting = false;
            $this->wake($task);
        }

        /**
         * Drop $task's reactor registration, if any. Idempotent. Unlinks from the
         * MIDDLE of the fd's waiter chain — a sibling parked on the same fd must
         * keep its own registration (and must not be reachable through a task that
         * has left, or the reactor would wake a corpse).
         */
        private function releaseIo(Task $task): void
        {
            $fd = $task->ioFd;
            if ($fd < 0) { return; }
            $task->ioFd = -1;
            $this->ioWaiters = $this->ioWaiters - 1;
            $write = $task->ioWrite;
            $head = $write
                ? (isset($this->writeWaiter[$fd]) ? $this->writeWaiter[$fd] : null)
                : (isset($this->readWaiter[$fd]) ? $this->readWaiter[$fd] : null);
            if ($head === null) { $task->ioNext = null; return; }
            if ($head === $task) {
                $next = $task->ioNext;
                if ($next === null) {
                    if ($write) { unset($this->writeWaiter[$fd]); } else { unset($this->readWaiter[$fd]); }
                } else {
                    if ($write) { $this->writeWaiter[$fd] = $next; } else { $this->readWaiter[$fd] = $next; }
                }
            } else {
                $prev = $head;
                while ($prev !== null && $prev->ioNext !== $task) { $prev = $prev->ioNext; }
                if ($prev !== null) { $prev->ioNext = $task->ioNext; }
            }
            $task->ioNext = null;
            if ($write) { $this->disarmWrite($fd); }
        }

        /**
         * Take $task off every channel queue it is parked on. Without this a
         * cancelled receiver still gets handed a value (it is PENDING until it
         * actually runs), so an unbuffered send returns as if delivered and the
         * value dies with the task; symmetrically a cancelled sender's value is
         * still taken by a later recv.
         */
        private function releaseChannel(Task $task): void
        {
            if ($task->chanHost !== null) {
                $task->chanHost->removeWaiter($task);
                $task->chanHost = null;
            }
            if (\count($task->selectChans) > 0) {
                foreach ($task->selectChans as $ch) {
                    $ch->removeWaiter($task);
                }
                $task->selectChans = [];
            }
        }

        /**
         * Run $body with cancellation held back — for cleanup that must itself
         * suspend (a close handshake, reaping just-cancelled children). Keep it
         * short: while shielded, this task cannot be stopped.
         */
        public function shielded(callable $body): mixed
        {
            $me = $this->running;
            $me->shield = $me->shield + 1;
            try {
                return $body();
            } finally {
                $me->shield = $me->shield - 1;
            }
        }

        /** Reap a scope's children even though this task is itself unwinding. */
        public function shieldedJoin(TaskGroup $g): void
        {
            $me = $this->running;
            $me->shield = $me->shield + 1;
            try {
                $g->joinAll();
            } finally {
                $me->shield = $me->shield - 1;
            }
        }

        /** Wait out one just-cancelled task, shielded (bounded: it was told to stop). */
        public function shieldedJoinTask(Task $t): void
        {
            $me = $this->running;
            $me->shield = $me->shield + 1;
            try {
                while ($t->state === Task::PENDING) {
                    $t->addWaiter($me);
                    \Fiber::suspend();
                }
            } finally {
                $me->shield = $me->shield - 1;
            }
        }

        // ── timers (binary min-heap, lazy delete) ──────────────────────────
        public function sleep(float $seconds): void
        {
            $this->checkCancel();
            $me = $this->running;
            $me->timerActive = true;
            // A DAEMON's timer must not read as "there is still work to do", or
            // the signal pump would keep every program alive forever. It still
            // rides the heap, so the loop wakes for it while other work exists.
            if (!$me->daemon) { $this->tmLive = $this->tmLive + 1; }
            $this->timerPush(\microtime(true) + $seconds, $me);
            \Fiber::suspend();
            if ($me->timerActive) {
                // Woken by something other than the timer (cancel already cleared
                // the flag) — drop the reservation; the heap slot dies lazily.
                $me->timerActive = false;
                if (!$me->daemon) { $this->tmLive = $this->tmLive - 1; }
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
                    $me->timerActive = false;
                    $this->tmLive = $this->tmLive - 1;
                }
            }
            return true;
        }

        /** Arm a timer on the RUNNING task without parking it (select with a deadline). */
        public function armTimer(float $deadline): void
        {
            $me = $this->running;
            $me->timerActive = true;
            $this->tmLive = $this->tmLive + 1;
            $this->timerPush($deadline, $me);
        }

        /** Drop the running task's timer reservation if it did not fire. */
        public function disarmTimer(): void
        {
            $me = $this->running;
            if ($me->timerActive) {
                $me->timerActive = false;
                $this->tmLive = $this->tmLive - 1;
            }
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

        /**
         * Push $me onto the head of $fd's READ waiter chain. Several tasks may
         * legitimately park on one fd (two readers on a shared stream); each
         * re-checks its own syscall after the wake, which is the level-triggered
         * contract this file already relies on.
         */
        private function chainRead(int $fd, Task $me): void
        {
            $me->ioNext = isset($this->readWaiter[$fd]) ? $this->readWaiter[$fd] : null;
            $this->readWaiter[$fd] = $me;
            $me->ioFd = $fd;
            $me->ioWrite = false;
            $this->ioWaiters = $this->ioWaiters + 1;
        }

        /** Push $me onto the head of $fd's WRITE waiter chain. */
        private function chainWrite(int $fd, Task $me): void
        {
            $me->ioNext = isset($this->writeWaiter[$fd]) ? $this->writeWaiter[$fd] : null;
            $this->writeWaiter[$fd] = $me;
            $me->ioFd = $fd;
            $me->ioWrite = true;
            $this->ioWaiters = $this->ioWaiters + 1;
        }

        /**
         * Wake EVERY task on $fd's chain for the given direction and clear the slot.
         * Waking only the head would strand the rest until the next readiness edge.
         */
        private function wakeChain(int $fd, bool $write): void
        {
            $t = $write
                ? (isset($this->writeWaiter[$fd]) ? $this->writeWaiter[$fd] : null)
                : (isset($this->readWaiter[$fd]) ? $this->readWaiter[$fd] : null);
            if ($write) { unset($this->writeWaiter[$fd]); } else { unset($this->readWaiter[$fd]); }
            while ($t !== null) {
                $next = $t->ioNext;
                $t->ioNext = null;
                $t->ioFd = -1;
                $this->ioWaiters = $this->ioWaiters - 1;
                $this->wake($t);
                $t = $next;
            }
        }

        /** Suspend until $conn is readable. */
        public function waitReadable(\Resource $conn): void
        {
            $this->checkCancel();
            $me = $this->running;
            $fd = $conn->addr;
            $this->ensureWatcher($conn, $fd);
            $this->chainRead($fd, $me);
            \Fiber::suspend();
            $this->releaseIo($me);
        }

        /**
         * Suspend until $conn is readable OR $seconds elapse. Returns true when
         * the reactor reported readiness, false on expiry — it throws nothing, so
         * the caller decides what a timeout means (stream_set_timeout reports
         * `timed_out`, stream_socket_accept answers false, the DNS exchange gives
         * up on the datagram).
         *
         * Both reservations are taken and both are released on either exit path:
         * a bounded wait that woke from the reactor must still drop its heap slot,
         * and one that expired must still leave the fd's waiter slot. cancelTask()
         * already releases io AND timer, so a cancelled bounded wait leaks neither.
         */
        public function waitReadableWithin(\Resource $conn, float $seconds): bool
        {
            $this->checkCancel();
            $me = $this->running;
            $fd = $conn->addr;
            $this->ensureWatcher($conn, $fd);
            $this->chainRead($fd, $me);
            $me->timerActive = true;
            if (!$me->daemon) { $this->tmLive = $this->tmLive + 1; }
            $this->timerPush(\microtime(true) + $seconds, $me);
            \Fiber::suspend();
            // The reactor clears ioFd when IT wakes the task; fireTimers clears
            // timerActive when the deadline does. Read both before releasing.
            $ready = $me->ioFd === -1;
            $this->releaseIo($me);
            if ($me->timerActive) {
                $me->timerActive = false;
                if (!$me->daemon) { $this->tmLive = $this->tmLive - 1; }
            }
            return $ready;
        }

        /**
         * Suspend until $conn is WRITABLE or $seconds elapse; true = writable,
         * false = expired. The writable twin of {@see waitReadableWithin()} — a
         * non-blocking connect(2) and a TLS handshake both complete on writability,
         * and without a deadline a peer that accepts the TCP and then goes silent
         * held the fiber forever (the caller's own connect timeout was ignored).
         */
        public function waitWritableWithin(\Resource $conn, float $seconds): bool
        {
            $this->checkCancel();
            $me = $this->running;
            $fd = $conn->addr;
            $this->ensureWatcher($conn, $fd);
            $this->chainWrite($fd, $me);
            if (!$this->writeArmed[$fd]) {
                $this->connWatcher[$fd]->modifyEvents([\Io\Poll\Event::Read, \Io\Poll\Event::Write]);
                $this->writeArmed[$fd] = true;
            }
            $me->timerActive = true;
            if (!$me->daemon) { $this->tmLive = $this->tmLive + 1; }
            $this->timerPush(\microtime(true) + $seconds, $me);
            \Fiber::suspend();
            $ready = $me->ioFd === -1;
            $this->releaseIo($me);
            if ($me->timerActive) {
                $me->timerActive = false;
                if (!$me->daemon) { $this->tmLive = $this->tmLive - 1; }
            }
            return $ready;
        }

        /** Suspend until $conn is writable — arms Write only while a writer waits. */
        public function waitWritable(\Resource $conn): void
        {
            $this->checkCancel();
            $me = $this->running;
            $fd = $conn->addr;
            $this->ensureWatcher($conn, $fd);
            $this->chainWrite($fd, $me);
            if (!$this->writeArmed[$fd]) {
                $this->connWatcher[$fd]->modifyEvents([\Io\Poll\Event::Read, \Io\Poll\Event::Write]);
                $this->writeArmed[$fd] = true;
            }
            \Fiber::suspend();
            $this->releaseIo($me);
        }

        // ── select(2): one task, many fds ──────────────────────────────────
        //
        // stream_select/socket_select used to POLL under the scheduler: poll(2)
        // with a zero timeout plus a fiber sleep backing off 0.2 ms → 10 ms. That
        // was correct but it cost up to 10 ms of latency per readiness edge and
        // burned a wake-up per backoff step — and it consumed one of the per-fd
        // waiter slots, so a select and a real reader on the same fd interfered.
        // These three register the task on EVERY fd it is interested in, park it
        // on the reactor once, and release the lot on the way out.

        /** Register the running task's interest in $fd. Registration only — no park. */
        public function selectAdd(int $fd, bool $write): void
        {
            $me = $this->running;
            $this->ensureWatcherFd($fd);
            if ($write && !$this->writeArmed[$fd]) {
                $this->connWatcher[$fd]->modifyEvents([\Io\Poll\Event::Read, \Io\Poll\Event::Write]);
                $this->writeArmed[$fd] = true;
            }
            $reg = new SelectReg($me, $fd, $write);
            $reg->fdNext = isset($this->selWaiter[$fd]) ? $this->selWaiter[$fd] : null;
            $this->selWaiter[$fd] = $reg;
            $reg->ownNext = $me->selHead;
            $me->selHead = $reg;
            $me->selFds = $me->selFds + 1;
        }

        /**
         * Park until one of the registered fds fires or $seconds elapse (< 0 =
         * unbounded). True = a watched fd fired. The caller re-polls to find WHICH,
         * so a spurious wake is harmless — the same level-triggered contract the
         * read/write chains run on.
         */
        public function selectWait(float $seconds): bool
        {
            $me = $this->running;
            if ($me->selFds === 0) { return false; }
            $this->checkCancel();
            $me->selWoken = false;
            // One I/O waiter per parked TASK, not per fd: the count answers "is
            // there still work", and a select is one piece of work.
            $this->ioWaiters = $this->ioWaiters + 1;
            if ($seconds >= 0.0) {
                $me->timerActive = true;
                if (!$me->daemon) { $this->tmLive = $this->tmLive + 1; }
                $this->timerPush(\microtime(true) + $seconds, $me);
            }
            \Fiber::suspend();
            // cancelTask() releases the records AND the waiter count for a select
            // it interrupted, so only decrement when they are still ours.
            if ($me->selFds > 0) { $this->ioWaiters = $this->ioWaiters - 1; }
            if ($me->timerActive) {
                $me->timerActive = false;
                if (!$me->daemon) { $this->tmLive = $this->tmLive - 1; }
            }
            return $me->selWoken;
        }

        /** Drop every registration this task holds. */
        public function selectDone(): void
        {
            $this->releaseSelect($this->running);
        }

        /**
         * Unlink $task's records from their fd chains. O(total registrations) —
         * a select's fd count is its own scale, and nothing hot goes through here.
         */
        private function releaseSelect(Task $task): void
        {
            $reg = $task->selHead;
            while ($reg !== null) {
                $next = $reg->ownNext;
                $fd = $reg->fd;
                $head = isset($this->selWaiter[$fd]) ? $this->selWaiter[$fd] : null;
                if ($head === $reg) {
                    if ($reg->fdNext === null) { unset($this->selWaiter[$fd]); }
                    else { $this->selWaiter[$fd] = $reg->fdNext; }
                } else {
                    $p = $head;
                    while ($p !== null && $p->fdNext !== $reg) { $p = $p->fdNext; }
                    if ($p !== null) { $p->fdNext = $reg->fdNext; }
                }
                $reg->fdNext = null;
                $reg->ownNext = null;
                if ($reg->write) { $this->disarmWrite($fd); }
                $reg = $next;
            }
            $task->selHead = null;
            $task->selFds = 0;
        }

        /** Wake every select waiting on $fd (all directions — the caller re-polls). */
        private function wakeSelect(int $fd): void
        {
            $reg = isset($this->selWaiter[$fd]) ? $this->selWaiter[$fd] : null;
            while ($reg !== null) {
                $reg->task->selWoken = true;
                $this->wake($reg->task);
                $reg = $reg->fdNext;
            }
        }

        /**
         * A watcher for a BARE fd — {@see \FdPollHandle}, which owns nothing. The
         * caller (a select over the user's own streams) guarantees the fd outlives
         * the registration, and wrapping their \Resource would either close it when
         * the wrapper died or leave the watcher pointing at a neutered one.
         */
        private function ensureWatcherFd(int $fd): void
        {
            if (!isset($this->connWatcher[$fd])) {
                $this->connWatcher[$fd] = $this->reactor->add(
                    new \FdPollHandle($fd), [\Io\Poll\Event::Read], $fd);
                $this->writeArmed[$fd] = false;
            }
        }

        /** Disarm Write once no writer is parked on $fd. */
        private function disarmWrite(int $fd): void
        {
            if (isset($this->writeArmed[$fd]) && $this->writeArmed[$fd]
                && !isset($this->writeWaiter[$fd]) && !$this->selectWantsWrite($fd)) {
                if (isset($this->connWatcher[$fd])) {
                    $this->connWatcher[$fd]->modifyEvents([\Io\Poll\Event::Read]);
                }
                $this->writeArmed[$fd] = false;
            }
        }

        /** Is any select still watching $fd for writability? */
        private function selectWantsWrite(int $fd): bool
        {
            $reg = isset($this->selWaiter[$fd]) ? $this->selWaiter[$fd] : null;
            while ($reg !== null) {
                if ($reg->write) { return true; }
                $reg = $reg->fdNext;
            }
            return false;
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
            // A task still parked on a closing fd would never be woken — resume
            // EVERY one so their read/write returns rather than wedging the loop.
            $this->wakeChain($fd, false);
            $this->wakeChain($fd, true);
            $this->wakeSelect($fd);
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
                $this->nReactorWaits = $this->nReactorWaits + 1;
                $ready = $this->reactor->wait($sec, $usec, null);
                foreach ($ready as $watcher) {
                    $fd = $watcher->getData();
                    $hup = $watcher->hasTriggered(\Io\Poll\Event::Error)
                        || $watcher->hasTriggered(\Io\Poll\Event::HangUp);
                    if (($hup || $watcher->hasTriggered(\Io\Poll\Event::Read))
                        && isset($this->readWaiter[$fd])) {
                        $this->wakeChain($fd, false);
                    }
                    if (($hup || $watcher->hasTriggered(\Io\Poll\Event::Write))
                        && isset($this->writeWaiter[$fd])) {
                        $this->wakeChain($fd, true);
                        $this->disarmWrite($fd);
                    }
                    // A select waiter takes any edge on any of its fds and re-polls
                    // to find out which — direction included.
                    if (isset($this->selWaiter[$fd])) {
                        $this->wakeSelect($fd);
                    }
                }
            } elseif ($timeout > 0) {
                // Safe despite usleep() now being fiber-aware: this runs in the
                // LOOP, outside every fiber, and AsyncHook::active() requires
                // \Fiber::getCurrent() — so it cannot re-enter the scheduler.
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
                if (!$t->daemon) { $this->tmLive = $this->tmLive - 1; }
                $this->nTimerFires = $this->nTimerFires + 1;
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
        return Scheduler::instance()->run($main, '');
    }

    /** @internal {@see async()} with the compiler-folded call site. */
    function __asyncAt(string $site, callable $main): mixed
    {
        return Scheduler::instance()->run($main, $site);
    }

    /**
     * Open a child scope and return the body's value — the everyday form of
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
        return TaskGroup::runAt('', $body);
    }

    /** @internal {@see group()} with the compiler-folded call site. */
    function __groupAt(string $site, callable $body): mixed
    {
        return TaskGroup::runAt($site, $body);
    }

    /**
     * Spawn a concurrent task into the scope open in THIS task, returning a handle.
     * Never fire-and-forget: the task is owned by, and joined at the end of, that
     * scope.
     */
    function spawn(callable $fn, mixed ...$args): Task
    {
        return __spawnAt('', $fn, ...$args);
    }

    /**
     * @internal {@see spawn()} carrying the `file:line` the compiler folded at the
     * call site (see {@see Task::$origin}). Never write this by hand — the
     * lowering rewrite produces it, and a hand-written `''` is what plain
     * `spawn()` already does.
     */
    function __spawnAt(string $site, callable $fn, mixed ...$args): Task
    {
        $group = Scheduler::instance()->currentGroup();
        if ($group === null) {
            throw new \LogicException('spawn() outside Async\\async() — no scope to own the task');
        }
        return $group->spawnAt($site, $fn, ...$args);
    }

    /**
     * Hold cancellation back for the duration of $body — for cleanup that must
     * itself suspend (a close handshake, a final flush). Without it a cancelled
     * task could never release anything that needs I/O, because every suspend
     * point re-raises. Keep it short: a shielded task cannot be stopped.
     */
    function shield(callable $body): mixed
    {
        return Scheduler::instance()->shielded($body);
    }

    /**
     * Run $body in a scope that must finish within $seconds. On expiry the whole
     * scope — the body AND everything it spawned — is cancelled and joined, then
     * {@see TimeoutException} is thrown. A timeout that leaves work running is
     * not a timeout.
     *
     *   $page = Async\timeout(2.0, fn() => file_get_contents($url));
     *
     * The deadline is visible inside via `Context::remaining()`, and it only ever
     * TIGHTENS an enclosing one ({@see TaskGroup::deadlineAt()}).
     *
     * ⚠ Cooperative: the body must reach a suspend point for the deadline to be
     * enforced. A purely CPU-bound body should call `Context::throwIfCancelled()`
     * at its loop head.
     */
    function timeout(float $seconds, callable $body): mixed
    {
        return __timeoutAt('', $seconds, $body);
    }

    /** @internal {@see timeout()} with the compiler-folded call site. */
    function __timeoutAt(string $site, float $seconds, callable $body): mixed
    {
        $sched = Scheduler::instance();
        if ($sched->currentGroup() === null) {
            throw new \LogicException('timeout() outside Async\\async() — no scope');
        }
        $cur = $sched->current();
        $group = new TaskGroup($cur->scope);
        $group->site = $site;
        $group->deadline = \microtime(true) + $seconds;
        $effective = $group->deadlineAt();   // an enclosing deadline still wins if nearer
        $prev = $cur->scope;
        $cur->scope = $group;

        // The body runs as a CHILD, not in this fiber: only a separate task can be
        // cancelled at its suspend point while we keep holding the timer.
        $task = $group->spawnAt($site, function () use ($body, $group) { return $body($group); });
        $settled = false;
        try {
            $settled = $sched->awaitDeadline($task, $effective);
            if (!$settled) {
                $group->cancel();
                $sched->shieldedJoin($group);
            } else {
                $group->joinAll();
            }
        } finally {
            $cur->scope = $prev;
        }

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
     * Wait for every task and return their results keyed by INPUT position.
     *
     * FAIL-FAST: the first failure cancels the tasks still running, joins them,
     * and rethrows that error — the structured-concurrency contract, not
     * Promise.allSettled. Every input is claimed BEFORE parking, so a sibling
     * that fails while this call is parked elsewhere is not mistaken for an
     * unhandled failure and escalated behind our back.
     *
     * OWNERSHIP: this takes responsibility for the tasks you hand it, so on
     * failure it cancels them — but only those owned by the CALLER's scope or one
     * nested inside it. A task borrowed from an enclosing scope, which someone
     * else may still be awaiting, is left alone.
     *
     * @return mixed[] results keyed by input position (0..N-1)
     */
    function awaitAll(Task ...$tasks): array
    {
        $n = \count($tasks);
        if ($n === 0) { return []; }
        $sched = Scheduler::instance();
        $scope = $sched->currentGroup();
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
            foreach ($tasks as $t) {
                if ($scope !== null && $scope->owns($t)) { $sched->cancelTask($t); }
            }
            // Join what we cancelled so none of it outlives this call. Shielded:
            // we may be unwinding ourselves, and a half-joined set leaks tasks.
            foreach ($tasks as $t) {
                if ($scope !== null && $scope->owns($t)) { $sched->shieldedJoinTask($t); }
            }
            throw $failure;
        }

        /** @var mixed[] $results */
        $results = [];
        foreach ($tasks as $i => $t) { $results[$i] = $t->result; }
        return $results;
    }

    /**
     * Wait for every task and report how each one turned out — never throws, and
     * never cancels anything. The counterpart to {@see awaitAll()} for the
     * "fetch a hundred of them and tell me which failed" shape.
     *
     * @return Settled[] keyed by input position (0..N-1)
     */
    function awaitAllSettled(Task ...$tasks): array
    {
        $n = \count($tasks);
        if ($n === 0) { return []; }
        $sched = Scheduler::instance();
        // Claiming is what keeps a failure from being escalated to the enclosing
        // scope behind our back — reporting it IS handling it.
        foreach ($tasks as $t) { $t->claimed = true; }

        while (true) {
            $pending = 0;
            foreach ($tasks as $t) {
                if ($t->state === Task::PENDING) { $pending = $pending + 1; }
            }
            if ($pending === 0) { break; }
            foreach ($tasks as $t) {
                if ($t->state === Task::PENDING) { $t->addWaiter($sched->current()); }
            }
            $sched->suspendCurrent();
        }

        /** @var Settled[] $out */
        $out = [];
        foreach ($tasks as $i => $t) {
            $out[$i] = $t->state === Task::DONE
                ? new Settled(true, $t->result, null)
                : new Settled(false, null, $t->error);
        }
        return $out;
    }

    /**
     * {@see mapConcurrent()} that collects outcomes instead of failing fast — at
     * most $limit in flight, one {@see Settled} per input position.
     *
     * Each body is wrapped so a throw never reaches the scope: a child that
     * actually FAILED would trip the group's fail-fast and cancel its siblings,
     * which is the opposite of settled semantics.
     *
     * @param mixed[] $items
     * @return Settled[]
     */
    function mapSettled(array $items, callable $fn, int $limit): array
    {
        if ($limit < 1) { $limit = 1; }
        $sem = new Semaphore($limit);
        return TaskGroup::run(function (TaskGroup $g) use ($items, $fn, $sem) {
            /** @var Task[] $tasks */
            $tasks = [];
            foreach ($items as $k => $item) {
                $tasks[$k] = $g->spawn(function () use ($sem, $fn, $item, $k) {
                    try {
                        return new Settled(true, $sem->withPermit(function () use ($fn, $item, $k) {
                            return $fn($item, $k);
                        }), null);
                    } catch (CancelledException $e) {
                        throw $e;          // our own scope stopping us is not a result
                    } catch (\Throwable $e) {
                        return new Settled(false, null, $e);
                    }
                });
            }
            foreach ($tasks as $t) { $t->claimed = true; }
            /** @var Settled[] $out */
            $out = [];
            foreach ($tasks as $k => $t) { $out[$k] = $t->await(); }
            return $out;
        });
    }

    /**
     * Wait for the FIRST successful task and return its value. No watcher fibers:
     * this parks directly on the inputs, so nothing is orphaned when a winner
     * appears. If every task failed, throws an {@see AggregateError} with the
     * errors keyed by INPUT position.
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

    /**
     * Map $fn over $items with at most $limit running at once — the everyday
     * "fetch 1000 URLs, 10 at a time". Results are keyed by input position; the
     * first failure cancels the rest and propagates (fail-fast, like awaitAll).
     *
     * @param mixed[] $items
     * @return mixed[]
     */
    function mapConcurrent(array $items, callable $fn, int $limit): array
    {
        if ($limit < 1) { $limit = 1; }
        $sem = new Semaphore($limit);
        return TaskGroup::run(function (TaskGroup $g) use ($items, $fn, $sem) {
            /** @var Task[] $tasks */
            $tasks = [];
            foreach ($items as $k => $item) {
                $tasks[$k] = $g->spawn(function () use ($sem, $fn, $item, $k) {
                    return $sem->withPermit(function () use ($fn, $item, $k) {
                        return $fn($item, $k);
                    });
                });
            }
            // Claim every task up front, then collect in input order — otherwise a
            // sibling failing while we await another looks unhandled and escalates.
            foreach ($tasks as $t) { $t->claimed = true; }
            /** @var mixed[] $out */
            $out = [];
            foreach ($tasks as $k => $t) { $out[$k] = $t->await(); }
            return $out;
        });
    }

    /**
     * Graceful shutdown: on any of $signals, cancel the ROOT scope.
     *
     *   Async\async(function () {
     *       Async\shutdownOn(SIGTERM, SIGINT);
     *       serveForever();                 // unwinds cleanly on the signal
     *   });
     *
     * This is the ONLY signal-aware thing in Async\, and it earns its place by
     * being about SCOPES, not about signals: everything else follows from
     * cancellation already being structured. The accept loop and every live
     * connection raise CancelledException at their next suspend point, each
     * scope joins its children, `shield()` covers a final flush, and async()
     * returns normally — a root cancellation is a shutdown, not a failure.
     *
     * Registering a handler is plain `pcntl_signal()`; the scheduler notices a
     * non-empty registry and starts pumping by itself, so an ordinary
     * `pcntl_signal(SIGHUP, …)` works inside async() with no async-specific
     * spelling. Process control (fork / workers / supervision) is NOT here —
     * it is under `Process\`, because none of it involves a scheduler.
     */
    function shutdownOn(int ...$signals): void
    {
        $sched = Scheduler::instance();
        foreach ($signals as $s) {
            \pcntl_signal($s, function () use ($sched) { $sched->cancelRoot(); });
        }
    }

    /**
     * Print {@see dump()} to STDERR whenever one of $signals arrives — Go's
     * SIGQUIT-dumps-every-goroutine, which is how you interrogate a process that
     * is ALREADY hung and that you cannot restart:
     *
     *   async(function () {
     *       Async\dumpOn(SIGQUIT);      // then: kill -QUIT <pid>
     *       serveForever();
     *   });
     *
     * SIGQUIT is the natural choice — it is not used for anything else here, and
     * the default disposition (core dump) is replaced by this. It is one line
     * rather than a default because a signal handler is the program's to own.
     *
     * The handler is ordinary async code: the scheduler runs the signal pump as a
     * daemon task, so this allocates and writes from inside the loop, and the
     * report it takes is a consistent snapshot rather than a half-updated one.
     */
    function dumpOn(int ...$signals): void
    {
        $sched = Scheduler::instance();
        foreach ($signals as $s) {
            \pcntl_signal($s, function () use ($sched) {
                \fwrite(\STDERR, $sched->report());
            });
        }
    }

    /**
     * Read $path in $chunk-sized pieces, YIELDING between them, and return the
     * whole contents ('' when it cannot be opened).
     *
     * Regular-file I/O has no readiness signal — O_NONBLOCK is a no-op for files on
     * both targets and there is no thread pool — so this is COOPERATIVE, not async:
     * each fread still blocks for its own chunk. That is enough, and MEASURED:
     * on a 64 MB page-cache-hot file a single `fread($h, 64 MB)` stalls every other
     * task for 15-25 ms, while this keeps the worst gap at ~2 ms (1 ms of which is
     * the observer's own tick granularity). A fork+socketpair worker pool was
     * considered and rejected: it would copy every byte through a socket, which for
     * a hot read costs more than the read itself, and it only pays off for a
     * genuinely blocking (cold / networked) filesystem.
     *
     * Cancellation-aware: the yield is Scheduler::sleep, so a cancelled task stops
     * at the next chunk boundary instead of reading the rest of the file.
     */
    function readFile(string $path, int $chunk = 1048576): string
    {
        $h = \fopen($path, 'rb');
        if ($h === false) {
            return '';
        }
        $sched = Scheduler::instance();
        $out = '';
        while (!\feof($h)) {
            $part = \fread($h, $chunk);
            if ($part === false) {
                break;
            }
            $str = (string)$part;
            if ($str === '') {
                break;
            }
            $out = $out . $str;
            $sched->sleep(0.0);
        }
        \fclose($h);
        return $out;
    }

    /**
     * Write $data to $path in $chunk-sized pieces, yielding between them. Returns
     * the byte count written, or -1 when the file cannot be opened. Same
     * cooperative contract as {@see readFile()}.
     */
    function writeFile(string $path, string $data, int $chunk = 1048576): int
    {
        $h = \fopen($path, 'wb');
        if ($h === false) {
            return -1;
        }
        $sched = Scheduler::instance();
        $len = \strlen($data);
        $off = 0;
        while ($off < $len) {
            $n = (int)\fwrite($h, \substr($data, $off, $chunk));
            if ($n <= 0) {
                break;
            }
            $off = $off + $n;
            $sched->sleep(0.0);
        }
        \fclose($h);
        return $off;
    }

    /**
     * A snapshot of the engine as text: every live task with its id, label and what
     * it is parked on (fd + direction, timer, channel, select), plus the live /
     * io-parked / timer / ready counts. The running task is marked `*`.
     *
     *   async(function () {
     *       Async\spawn(fn() => serve())->named('http');
     *       pcntl_signal(SIGQUIT, fn() => print Async\dump());   // hung? ask it
     *   });
     *
     * Returns '' when no scheduler is running. A DeadlockException already embeds
     * this, because "all tasks are asleep" alone tells you nothing.
     */
    function dump(): string
    {
        return Scheduler::hasInstance() ? Scheduler::instance()->report() : '';
    }

    /**
     * Watch for a task that HOLDS THE LOOP longer than $milliseconds, and name it
     * on STDERR when one does. 0 turns it off; `MANTICORE_ASYNC_WATCHDOG=<ms>`
     * does the same thing without touching the program.
     *
     *   async: watchdog — task #4 "report" at app.php:88 held the loop 214.3 ms (limit 50 ms)
     *
     * This is the answer to "everything stalled and nothing says why". A
     * cooperative loop cannot preempt, so the report necessarily comes AFTER the
     * stall — but the failure modes it catches are otherwise invisible: regular
     * file I/O (blocking BY DESIGN here, see Async\readFile), a CPU-bound stretch
     * with no suspend point, a third-party library falling back to a blocking
     * call. Each task reports its first breach and then only a doubling, so a
     * knowingly CPU-heavy worker does not flood the log.
     */
    function watchdog(float $milliseconds): void
    {
        Scheduler::instance()->setWatchdog($milliseconds);
    }

    /**
     * Engine counters — monotonic totals (`spawned`, `settled`, `cancelled`,
     * `wakes`, `reactor_waits`, `timer_fires`, `watchdog`) plus the current gauges
     * (`live`, `ready`, `io_parked`, `timers`). Empty outside a scheduler.
     *
     * For benchmarks and for asserting engine BEHAVIOUR in a test — e.g. that a
     * run did not silently fall back to polling (`reactor_waits`), or that the
     * watchdog fired exactly once.
     *
     * @return array<string,int>
     */
    function stats(): array
    {
        return Scheduler::hasInstance() ? Scheduler::instance()->stats() : [];
    }

    /** @internal milliseconds, one decimal, for the watchdog line. */
    function __ms(float $seconds): string
    {
        return (string)\round($seconds * 1000.0, 1);
    }

    /** Suspend the current task for $seconds without blocking the loop. */
    function delay(float $seconds): void
    {
        Scheduler::instance()->sleep($seconds);
    }

    /** Make a channel; capacity 0 = unbuffered rendezvous. */
    function channel(int $capacity = 0): Channel
    {
        return new Channel($capacity);
    }

    /**
     * Wait for whichever case is ready first. Cases are {@see SelectCase}s; a bare
     * Channel is shorthand for a receive.
     *
     *   $r = Async\select([$a, SelectCase::send($b, $v)]);
     *   if ($r->isSend) { … } else { echo $r->value; }
     *
     * Exactly one case fires: a waiter parked across several channels is won by
     * the FIRST channel to reach it, and the losers see the claim and skip it.
     *
     * @param array<int, Channel|SelectCase> $cases
     */
    function select(array $cases): Selected
    {
        $r = __selectImpl($cases, true, null);
        // Blocking mode only returns null if it somehow ran dry; the loop's own
        // deadlock detection is the backstop, so this cannot be reached normally.
        if ($r === null) {
            throw new DeadlockException('select: no case can ever fire');
        }
        return $r;
    }

    /**
     * The non-blocking form (Go's `default:` arm): the first ready case, or null
     * when nothing is ready right now.
     *
     * @param array<int, Channel|SelectCase> $cases
     */
    function selectNow(array $cases): ?Selected
    {
        return __selectImpl($cases, false, null);
    }

    /**
     * select() with a deadline (Go's `time.After` arm): null on expiry.
     *
     * @param array<int, Channel|SelectCase> $cases
     */
    function selectWithin(float $seconds, array $cases): ?Selected
    {
        return __selectImpl($cases, true, \microtime(true) + $seconds);
    }

    /**
     * @param array<int, Channel|SelectCase> $cases
     * @internal shared body of select / selectNow / selectWithin
     */
    function __selectImpl(array $cases, bool $block, ?float $deadline): ?Selected
    {
        $n = \count($cases);
        if ($n === 0) {
            throw new \LogicException('select() with no cases blocks forever');
        }
        $sched = Scheduler::instance();

        /** @var SelectCase[] $norm */
        $norm = [];
        foreach ($cases as $c) {
            $norm[] = ($c instanceof SelectCase) ? $c : SelectCase::recv($c);
        }

        // Fast path: take the first case that is ready without parking.
        foreach ($norm as $i => $c) {
            if ($c->kind === SelectCase::SEND) {
                if ($c->channel->trySelectSend($c->value)) {
                    return new Selected($i, null, true, $c->channel, true);
                }
            } else {
                $r = $c->channel->trySelectRecv();
                if ($r !== null) {
                    return new Selected($i, $r->value, $r->ok, $c->channel, false);
                }
            }
        }
        if (!$block) {
            return null;
        }

        // Slow path: park on every case and let the first one to complete claim us.
        $sched->checkCancel();
        $me = $sched->current();
        $me->selecting = true;
        $me->selectClaimed = false;
        $me->chanReady = null;
        $me->chanValue = null;
        $me->chanOk = true;
        $me->chanSend = false;
        /** @var Channel[] $parked */
        $parked = [];
        foreach ($norm as $c) {
            if ($c->kind === SelectCase::SEND) {
                $c->channel->registerSelectSend($me, $c->value);
            } else {
                $c->channel->registerSelectRecv($me);
            }
            $parked[] = $c->channel;
        }
        $me->selectChans = $parked;
        if ($deadline !== null) {
            $sched->armTimer($deadline);
        }
        \Fiber::suspend();
        if ($deadline !== null) {
            $sched->disarmTimer();
        }

        $me->selecting = false;
        $me->selectChans = [];
        $fired = $me->chanReady;
        $isSend = $me->chanSend;
        $me->chanReady = null;
        // Deregister from every channel that did NOT win (the winner popped us).
        foreach ($parked as $ch) {
            if ($ch !== $fired) { $ch->removeWaiter($me); }
        }
        if ($fired === null) {
            return null;   // the deadline fired first
        }
        $idx = -1;
        foreach ($norm as $i => $c) {
            $wantSend = $c->kind === SelectCase::SEND;
            if ($c->channel === $fired && $wantSend === $isSend) { $idx = $i; break; }
        }
        return new Selected($idx, $isSend ? null : $me->chanValue, $me->chanOk, $fired, $isSend);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // INTERNAL: the raw fd layer.
    //
    // NOT the API. Ordinary fread/fwrite/stream_socket_accept/fclose already
    // suspend the fiber through the netpoller seam, and they are what PHP code
    // should be written against. These bypass the stream layer entirely — no
    // buffering, no TLS, `$conn->addr` read as a bare fd — and exist only for the
    // syscall-count benchmarks, where skipping stream stdio is worth ~2×. Mixing
    // them with fread/fwrite on the same resource loses whatever the stream layer
    // had buffered.
    // ═══════════════════════════════════════════════════════════════════════

    #[\Ffi\Library('c'), \Ffi\Symbol('recv')]
    function sys_recv(int $fd, \Ffi\Ptr $buf, int $len, int $flags): int {}

    #[\Ffi\Library('c'), \Ffi\Symbol('send')]
    function sys_send(int $fd, string $buf, int $len, int $flags): int {}

    #[\Ffi\Library('c'), \Ffi\Symbol('fork')]
    function sys_fork(): int {}

    #[\Ffi\Library('c'), \Ffi\Symbol('getpid')]
    function sys_getpid(): int {}

    /** @internal Connect to $addr (e.g. "tcp://127.0.0.1:8080"), non-blocking. */
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

    /** @internal Accept the next connection, suspending until one arrives. */
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

    /** @internal Drop the connection's persistent reactor watcher, then close it. */
    function close(\Resource $conn): void
    {
        Scheduler::instance()->closeConn($conn);
        \fclose($conn);
    }

    /** @internal Read up to $length bytes (raw recv), suspending until readable. */
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

    /** @internal Write all of $data (raw send), suspending on back-pressure. */
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
}
