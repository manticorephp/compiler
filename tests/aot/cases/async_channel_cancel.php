<?php
// Cancelling a task parked on a channel must take it OFF the queues. Without
// that a cancelled receiver is still handed a value (it is PENDING until it
// actually runs), so an unbuffered send returns as if delivered and the message
// dies with the task; symmetrically a cancelled sender's value is still taken.

use function Async\async;
use function Async\channel;
use function Async\delay;
use function Async\group;
use Async\Scheduler;
use Async\TaskGroup;

final class Box { public int $n = 0; public bool $hit = false; }

async(function () {
    // A cancelled RECEIVER must not swallow the next message.
    $ch = channel();
    $b = new Box();
    group(function (TaskGroup $g) use ($ch, $b) {
        $dead = $g->spawn(function () use ($ch) { $ch->recv(); });
        delay(0.01);                                   // let it park in recvQ
        Scheduler::instance()->cancelTask($dead);
        $g->spawn(function () use ($ch, $b) { $b->n = $ch->recv(); });
        delay(0.01);                                   // let the live one park
        $ch->send(42);
    });
    echo "receiver: live got ", $b->n, "\n";

    // A cancelled SENDER's value must not be taken by a later recv.
    $ch2 = channel();
    $b2 = new Box();
    group(function (TaskGroup $g) use ($ch2, $b2) {
        $dead = $g->spawn(function () use ($ch2) { $ch2->send(7); });
        delay(0.01);                                   // parks in sendQ, no receiver
        Scheduler::instance()->cancelTask($dead);
        $g->spawn(function () use ($ch2) { $ch2->send(8); });
        delay(0.01);
        $b2->n = $ch2->recv();
    });
    echo "sender: took ", $b2->n, "\n";
    echo "done\n";
});
