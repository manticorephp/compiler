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

    /**
     * Wait for every child to settle. A settled child is pruned from the list by
     * {@see childSettled} (so a long-lived scope — e.g. a server's root group —
     * does not accumulate finished tasks and leak their fibers), so this drains
     * until empty rather than iterating a fixed snapshot. First failure cancels
     * the rest.
     */
    public function joinAll(): void
    {
        while (\count($this->children) > 0) {
            $child = $this->children[0];
            if ($child->state === Task::PENDING) {
                try {
                    $child->await();
                } catch (\Throwable $e) {
                    $this->fail($e);
                }
            } else {
                // Already settled but not yet pruned (no awaiter ran) — drop it.
                $this->childSettled($child);
                if ($child->state === Task::FAILED) {
                    $this->fail($child->error);
                }
            }
        }
    }

    /** Drop a settled child so its Task + fiber can be reclaimed (no leak). O(1),
     *  order-independent: swap the match with the last element and pop. */
    public function childSettled(Task $task): void
    {
        $n = \count($this->children);
        for ($i = 0; $i < $n; $i++) {
            if ($this->children[$i] === $task) {
                $this->children[$i] = $this->children[$n - 1];
                \array_pop($this->children);
                return;
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
