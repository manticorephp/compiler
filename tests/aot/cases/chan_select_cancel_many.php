<?php
// Cancelling many tasks that are all parked in select() over the same channels.
//
// This is the shape the cursor queues and tombstones were built for, and the one
// case they did not cover: an ordinary send()/next() waiter carries its slot and is
// tombstoned in O(1), but a select() waiter sits in SEVERAL queues at once, so it
// used to be found by scanning every queue's live tail — once per channel, per
// cancelled task. It now carries one slot per queue instead.
//
// The assertion is behavioural, not a timing: every task must deregister from every
// queue it parked on, so a later send finds an EMPTY channel rather than one full of
// dead waiters. A missed deregistration shows up as a send being taken by a
// cancelled task, and the live receiver never getting it.
//
// The last group parks a select with a send AND a recv on the SAME channel, which is
// the case a single per-task slot cannot describe at all.
//
// Manticore-only (structured concurrency is superset), so nothing prints before the
// first Async call.

use function Async\async;
use function Async\spawn;
use function Async\delay;
use function Async\select;
use Async\SelectCase;

async(function () {
    $a = new \Async\Channel(0);
    $b = new \Async\Channel(0);
    $c = new \Async\Channel(0);

    // 300 tasks, each parked on all three channels at once — 900 queue entries.
    $waiters = [];
    for ($i = 0; $i < 300; $i++) {
        $waiters[] = spawn(function () use ($a, $b, $c) {
            select([$a, $b, $c]);
            return 'woke';
        });
    }
    delay(0.01);                 // let every one of them reach the park

    foreach ($waiters as $w) {
        $w->cancel();
    }
    delay(0.01);                 // and let every cancellation be delivered

    // Every queue must be empty now. An unbuffered send with no receiver cannot
    // complete, so all three refuse it — if a cancelled task were still parked, one
    // of them would "succeed" into a waiter that is never coming back.
    echo "a takes: ", $a->trySelectSend(1) ? 'yes' : 'no', "\n";
    echo "b takes: ", $b->trySelectSend(1) ? 'yes' : 'no', "\n";
    echo "c takes: ", $c->trySelectSend(1) ? 'yes' : 'no', "\n";

    // And the channels still work: one live receiver, one send, one delivery.
    $live = spawn(function () use ($a) { return (string)$a->recv(); });
    delay(0.01);
    echo "live receiver takes: ", $a->trySelectSend('ok') ? 'yes' : 'no', "\n";
    echo "received: ", (string)$live->await(), "\n";

    // Both directions of ONE channel in a single select, cancelled the same way.
    $d = new \Async\Channel(0);
    $both = [];
    for ($i = 0; $i < 50; $i++) {
        $both[] = spawn(function () use ($d) {
            // The documented shorthand — a bare Channel beside a SelectCase — which
            // is a literal of two unrelated classes, so it is also the shape that
            // used to erase its element and SIGSEGV inside select().
            select([$d, SelectCase::send($d, 1)]);
            return 'woke';
        });
    }
    delay(0.01);
    foreach ($both as $w) {
        $w->cancel();
    }
    delay(0.01);
    echo "d takes after cancel: ", $d->trySelectSend(2) ? 'yes' : 'no', "\n";

    $tail = spawn(function () use ($d) { return (string)$d->recv(); });
    delay(0.01);
    echo "d takes with a receiver: ", $d->trySelectSend('done') ? 'yes' : 'no', "\n";
    echo "tail received: ", (string)$tail->await(), "\n";
});
