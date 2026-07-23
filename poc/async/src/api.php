<?php

namespace Async;

/**
 * Run $main to completion on the async engine. Opens the implicit ROOT scope, so
 * a top-level {@see spawn()} is still structured — the program does not exit until
 * every spawned task has settled. The single entry point for an async program.
 */
function run(callable $main): void
{
    Scheduler::instance()->run($main);
}

/**
 * Spawn a concurrent task into the current scope and return a handle to await.
 * There is always a scope (the root one {@see run()} opens), so this is never
 * fire-and-forget: the task is owned by, and joined at the end of, that scope.
 */
function spawn(callable $fn, mixed ...$args): Task
{
    $group = Scheduler::instance()->currentGroup();
    if ($group === null) {
        throw new \LogicException('spawn() outside Async\\run() — no scope to own the task');
    }
    return $group->spawn($fn, ...$args);
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
