<?php

namespace Async;

/**
 * A structured-concurrency scope (a "nursery"). Every {@see spawn()} attaches to
 * the innermost open group; a group does not close until ALL its children have
 * settled — so a spawned task can never outlive its scope (no fire-and-forget).
 * If one child throws, its siblings are cancelled and the error propagates out of
 * the group.
 *
 * Usage:
 *   TaskGroup::run(function (TaskGroup $g) {
 *       $g->spawn(fn() => work(1));
 *       $g->spawn(fn() => work(2));
 *   }); // returns only once both children are done
 *
 * A top-level {@see spawn()} attaches to the implicit ROOT group that {@see run()}
 * opens for the whole program.
 */
final class TaskGroup
{
    /** @var Task[] children spawned into this group */
    public array $children = [];
    public bool $cancelled = false;
    public ?\Throwable $failure = null;

    public function __construct(public ?TaskGroup $parent = null) {}

    /** Open a scope, run $body($group), then join every child before returning. */
    public static function run(callable $body): mixed
    {
        $sched = Scheduler::instance();
        $group = new TaskGroup($sched->currentGroup());
        $sched->pushGroup($group);
        try {
            $result = $body($group);
            $group->joinAll();
        } finally {
            $sched->popGroup();
        }
        if ($group->failure !== null) {
            throw $group->failure;
        }
        return $result;
    }

    /** Spawn a child task into this group. Returns a handle to await. */
    public function spawn(callable $fn, mixed ...$args): Task
    {
        $task = Scheduler::instance()->newTask($fn, $args, $this);
        $this->children[] = $task;
        return $task;
    }

    /** Wait for every child to settle. First failure cancels the rest. */
    public function joinAll(): void
    {
        foreach ($this->children as $child) {
            if ($child->state === Task::PENDING) {
                try {
                    $child->await();
                } catch (\Throwable $e) {
                    $this->fail($e);
                }
            } elseif ($child->state === Task::FAILED && $this->failure === null) {
                $this->failure = $child->error;
                $this->cancel();
            }
        }
    }

    /** Record the first failure and cancel the remaining children. */
    public function fail(\Throwable $e): void
    {
        if ($this->failure === null) {
            $this->failure = $e;
        }
        $this->cancel();
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        // Cooperative: a child observes cancellation at its next suspend point
        // (I/O, delay, throwIfCancelled). Spike keeps it best-effort.
    }
}
