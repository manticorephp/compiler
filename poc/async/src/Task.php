<?php

namespace Async;

/**
 * A running unit of async work — a fiber plus its result/error state. Created by
 * {@see spawn()} / {@see TaskGroup::spawn()}; never constructed directly by user
 * code. `await()` suspends the calling task until this one finishes and yields
 * its value (or re-throws its error).
 */
final class Task
{
    public const PENDING = 0;
    public const DONE = 1;
    public const FAILED = 2;

    public int $state = self::PENDING;
    public mixed $result = null;
    public ?\Throwable $error = null;

    /** @var Task[] tasks awaiting THIS one — resumed when it settles */
    public array $waiters = [];

    /** True while suspended on I/O (its connection's persistent watcher is armed). */
    public bool $ioWaiting = false;

    /** A value handed to this task while it was parked on a {@see Channel}. */
    public mixed $chanValue = null;

    public function __construct(
        public \Fiber $fiber,
        public TaskGroup $group,
    ) {}

    public function isDone(): bool { return $this->state !== self::PENDING; }

    /** Suspend the calling task until this one settles, then return its value. */
    public function await(): mixed
    {
        if ($this->state === self::PENDING) {
            $sched = Scheduler::instance();
            $this->waiters[] = $sched->current();
            $sched->suspendCurrent();
        }
        if ($this->state === self::FAILED) {
            throw $this->error;
        }
        return $this->result;
    }
}
