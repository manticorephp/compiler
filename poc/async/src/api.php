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
