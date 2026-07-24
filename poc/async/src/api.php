<?php

namespace Async;

/**
 * Run $main to completion on the async engine. Opens the implicit ROOT scope, so
 * a top-level {@see spawn()} is still structured — the program does not exit until
 * every spawned task has settled. The single entry point for an async program.
 */
function async(callable $main): void
{
    Scheduler::instance()->run($main);
}

/**
 * Spawn a concurrent task into the current scope and return a handle to await.
 * There is always a scope (the root one {@see async()} opens), so this is never
 * fire-and-forget: the task is owned by, and joined at the end of, that scope.
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
 * Wait for EVERY task to settle, then return their results keyed by input position.
 * Does not fail fast: siblings keep running even after one throws, so this collects
 * the whole outcome. If any task failed, an {@see AggregateError} is thrown carrying
 * every error keyed by its task's position (successful results are not returned in
 * that case). Empty input returns `[]`.
 *
 * @return mixed[] results keyed by input position (0..N-1)
 */
function awaitAll(Task ...$tasks): array
{
    $results = [];
    $errors = [];
    foreach ($tasks as $i => $t) {
        try {
            $results[$i] = $t->await();
        } catch (\Throwable $e) {
            $errors[$i] = $e;
        }
    }
    if (\count($errors) > 0) {
        throw new AggregateError('awaitAll: one or more tasks failed', $errors);
    }
    return $results;
}

/**
 * Wait for the FIRST successful task and return its value (Promise.any semantics).
 * Failures are ignored while any candidate remains; if every task fails, throws an
 * {@see AggregateError} with all errors keyed by task position. Siblings that finish
 * after the winning task are not cancelled — they settle under the enclosing scope.
 */
function awaitAny(Task ...$tasks): mixed
{
    if (\count($tasks) === 0) {
        throw new \LogicException('awaitAny() with no tasks blocks forever');
    }
    // Fast path: a task already succeeded — nothing to spawn/park on.
    foreach ($tasks as $t) {
        if ($t->state === Task::DONE) {
            return $t->result;
        }
    }
    // Fan-in via a buffered channel: one watcher per input reports its outcome,
    // we drain until the first success (or exhaust everyone → all-failed).
    $group = Scheduler::instance()->currentGroup();
    if ($group === null) {
        throw new \LogicException('awaitAny() outside Async\\async() — no scope to own the watchers');
    }
    $n = \count($tasks);
    $ch = new Channel($n);   // cap = N so no watcher ever blocks on send
    foreach ($tasks as $i => $t) {
        $group->spawn(function () use ($t, $ch, $i): void {
            try {
                $v = $t->await();
                $ch->send([$i, true, $v]);
            } catch (\Throwable $e) {
                $ch->send([$i, false, $e]);
            }
        });
    }
    $errors = [];
    for ($k = 0; $k < $n; $k++) {
        [$i, $ok, $v] = $ch->recv();
        if ($ok) {
            return $v;
        }
        $errors[$i] = $v;
    }
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
 * Receive from whichever of $channels is ready first (Go's `select` over receives).
 * Parks on all of them and wakes on the first delivery; a strict single-claim in
 * {@see Channel::wakeReceiver()} guarantees exactly one case fires even though the
 * scheduler is cooperative (a losing channel skips the already-claimed waiter).
 *
 * @param Channel[] $channels
 * @return array{0: int, 1: mixed, 2: bool} [index of the channel that fired, value, ok]
 *         `ok` is false when that channel was closed (value is null).
 */
function select(array $channels): array
{
    if (\count($channels) === 0) {
        throw new \LogicException('select() with no channels blocks forever');
    }
    $sched = Scheduler::instance();

    // Fast path: take from the first channel that already has something.
    foreach ($channels as $i => $ch) {
        $r = $ch->trySelectRecv();
        if ($r[0]) {
            return [$i, $r[1], $r[2]];
        }
    }

    // Slow path: park on every channel, wait for the first to deliver.
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

    // Woken: exactly one channel claimed us; deregister from the losers.
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
