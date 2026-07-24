<?php

namespace Async;

use Io\Poll\Context as PollContext;
use Io\Poll\Backend;
use Io\Poll\Event;

/**
 * The async engine: a cooperative fiber loop multiplexed over an Io\Poll reactor
 * and a timer set. One per process (singleton). Not part of the public surface —
 * user code goes through {@see run()}, {@see spawn()}, {@see delay()} and the
 * `Async\` I/O helpers.
 *
 * The loop: drain the ready queue (resume each fiber until it suspends or
 * finishes); then block in the reactor until an fd is ready or the next timer is
 * due; wake the corresponding tasks; repeat until no work remains.
 */
final class Scheduler
{
    private static ?Scheduler $instance = null;

    /** @var Task[] tasks ready to resume (the run queue) */
    private array $ready = [];
    /** @var TaskGroup[] open scopes; the top is the current group */
    private array $groups = [];
    private ?Task $running = null;

    private PollContext $reactor;
    private int $ioCount = 0;
    /** @var array<int, \Io\Poll\Watcher> fd → its PERSISTENT reactor watcher */
    private array $connWatcher = [];
    // A single reused recv scratch buffer. Safe to share: a read copies out to a
    // string (str_from_buffer) before the fiber can suspend, so no two reads hold
    // it at once (cooperative). Grows on demand; never freed per read.
    private ?\Ffi\Ptr $rbuf = null;
    private int $rbufLen = 0;
    /** @var array<int, float> task-id → deadline (seconds); parallel to timerTask */
    private array $timerDeadline = [];
    /** @var array<int, Task> task-id → task waiting on a timer */
    private array $timerTask = [];
    private int $nextId = 1;

    private function __construct()
    {
        $this->reactor = new PollContext(Backend::Auto);
    }

    public static function instance(): Scheduler
    {
        if (self::$instance === null) {
            self::$instance = new Scheduler();
        }
        return self::$instance;
    }

    // ── scope stack ────────────────────────────────────────────────────────
    public function currentGroup(): ?TaskGroup
    {
        $n = \count($this->groups);
        return $n > 0 ? $this->groups[$n - 1] : null;
    }
    public function pushGroup(TaskGroup $g): void { $this->groups[] = $g; }
    public function popGroup(): void { \array_pop($this->groups); }

    public function current(): Task
    {
        return $this->running;
    }

    /** The reused recv scratch buffer, grown to at least $len bytes. */
    public function readBuf(int $len): \Ffi\Ptr
    {
        if ($this->rbuf === null || $len > $this->rbufLen) {
            if ($this->rbuf !== null) { \Runtime\Libc\free($this->rbuf); }
            $this->rbuf = \Runtime\Libc\calloc($len, 1);
            $this->rbufLen = $len;
        }
        return $this->rbuf;
    }

    // ── task creation ──────────────────────────────────────────────────────
    /** @param mixed[] $args */
    public function newTask(callable $fn, array $args, TaskGroup $group): Task
    {
        $fiber = new \Fiber(function () use ($fn, $args) {
            return $fn(...$args);
        });
        $task = new Task($fiber, $group);
        $this->ready[] = $task;
        return $task;
    }

    // ── the run entry ──────────────────────────────────────────────────────
    public function run(callable $main): void
    {
        $root = new TaskGroup(null);
        $this->pushGroup($root);
        $rootTask = $this->newTask($main, [], $root);
        $root->children[] = $rootTask;
        $this->installNetpoller();
        $this->loop();
        $this->clearNetpoller();
        $this->popGroup();
        if ($root->failure !== null) {
            throw $root->failure;
        }
    }

    // ── transparent I/O: expose suspend/close to the stdlib stream layer ──────
    // Plain fread/fwrite/stream_socket_accept/fclose consult \Runtime\AsyncHook and
    // route their would-block through these, so ordinary blocking-looking PHP I/O
    // suspends the fiber instead of the process (the Go netpoller model).
    private function installNetpoller(): void
    {
        // Method call (links by symbol across the stdlib boundary), not a direct
        // AsyncHook::$prop write — a cross-unit static-prop store needs class/type
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
        while (\count($this->ready) > 0 || $this->ioCount > 0 || \count($this->timerTask) > 0) {
            while (\count($this->ready) > 0) {
                $task = \array_shift($this->ready);
                if ($task->state !== Task::PENDING) { continue; }
                $this->step($task);
            }
            if (\count($this->ready) === 0) {
                $this->pollAndTick();
            }
        }
    }

    /** Resume one task's fiber; settle it when the fiber finishes/throws. */
    private function step(Task $task): void
    {
        $prev = $this->running;
        $this->running = $task;
        try {
            if (!$task->fiber->isStarted()) {
                $task->fiber->start();
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
        foreach ($task->waiters as $w) {
            $this->wake($w);
        }
        if ($state === Task::FAILED && \count($task->waiters) === 0) {
            // Nobody awaits it — surface via the enclosing scope so the error is
            // never silently lost (the point of structured concurrency).
            $task->group->fail($error);
        }
        // Drop the finished task from its scope so a long-lived group (a server's
        // root) does not accumulate completed tasks and leak their fibers. An
        // awaiter keeps its own reference, so its result survives the prune.
        $task->group->childSettled($task);
    }

    // ── suspend / wake ─────────────────────────────────────────────────────
    /** Yield the running task back to the loop (it is re-queued elsewhere). */
    public function suspendCurrent(): void
    {
        \Fiber::suspend();
    }

    public function wake(Task $task): void
    {
        if ($task->state === Task::PENDING) {
            $this->ready[] = $task;
        }
    }

    // ── timers ─────────────────────────────────────────────────────────────
    public function sleep(float $seconds): void
    {
        $id = $this->nextId; $this->nextId = $this->nextId + 1;
        $this->timerDeadline[$id] = \microtime(true) + $seconds;
        $this->timerTask[$id] = $this->running;
        $this->suspendCurrent();
    }

    // ── I/O readiness (PERSISTENT registration) ────────────────────────────
    // A connection's fd is registered ONCE (level-triggered Read) and kept for the
    // connection's life — no add/remove kevent per read. Safe because a fiber only
    // suspends on I/O after a recv/send returned EWOULDBLOCK (the fd is drained),
    // so the level-triggered watcher does not re-fire until NEW readiness arrives.

    /** Suspend until $conn is readable; registers a persistent watcher on first use. */
    public function waitReadable(\Resource $conn): void
    {
        $fd = $conn->addr;
        if (!isset($this->connWatcher[$fd])) {
            $this->connWatcher[$fd] = $this->reactor->add(new \StreamPollHandle($conn), [Event::Read], $this->running);
            $this->ioCount = $this->ioCount + 1;
        }
        $this->running->ioWaiting = true;
        $this->suspendCurrent();
    }

    /** Suspend until $conn is writable — arms Write on the persistent watcher, then disarms. */
    public function waitWritable(\Resource $conn): void
    {
        $fd = $conn->addr;
        if (!isset($this->connWatcher[$fd])) {
            $this->connWatcher[$fd] = $this->reactor->add(new \StreamPollHandle($conn), [Event::Read, Event::Write], $this->running);
            $this->ioCount = $this->ioCount + 1;
        } else {
            $this->connWatcher[$fd]->modifyEvents([Event::Read, Event::Write]);
        }
        $this->running->ioWaiting = true;
        $this->suspendCurrent();
        if (isset($this->connWatcher[$fd])) {
            $this->connWatcher[$fd]->modifyEvents([Event::Read]);   // back to read-only
        }
    }

    /** Drop $conn's persistent watcher (call before fclose so it stops firing). */
    public function closeConn(\Resource $conn): void
    {
        $fd = $conn->addr;
        if (isset($this->connWatcher[$fd])) {
            $this->connWatcher[$fd]->remove();
            unset($this->connWatcher[$fd]);
            $this->ioCount = $this->ioCount - 1;
        }
    }

    // ── the blocking step: wait for fds / the next timer, wake tasks ────────
    private function pollAndTick(): void
    {
        $now = \microtime(true);
        $timeout = $this->nextTimeout($now);   // seconds, or -1 to block

        if ($this->ioCount > 0) {
            $sec = $timeout < 0 ? null : (int)$timeout;
            $usec = $timeout < 0 ? 0 : (int)(($timeout - (int)$timeout) * 1000000);
            /** @var \Io\Poll\Watcher[] $ready */
            $ready = $this->reactor->wait($sec, $usec, null);
            foreach ($ready as $watcher) {
                $task = $watcher->getData();
                if ($task->ioWaiting) {   // persistent watcher stays; only wake if actually waiting
                    $task->ioWaiting = false;
                    $this->wake($task);
                }
            }
        } elseif ($timeout > 0) {
            \usleep((int)($timeout * 1000000));
        }
        $this->fireTimers();
    }

    private function nextTimeout(float $now): float
    {
        $best = -1.0;
        foreach ($this->timerDeadline as $id => $deadline) {
            $dt = $deadline - $now;
            if ($dt < 0) { $dt = 0.0; }
            if ($best < 0 || $dt < $best) { $best = $dt; }
        }
        return $best;
    }

    private function fireTimers(): void
    {
        $now = \microtime(true);
        /** @var int[] $due */
        $due = [];
        foreach ($this->timerDeadline as $id => $deadline) {
            if ($deadline <= $now) { $due[] = $id; }
        }
        foreach ($due as $id) {
            $task = $this->timerTask[$id];
            unset($this->timerDeadline[$id]);
            unset($this->timerTask[$id]);
            $this->wake($task);
        }
    }
}
