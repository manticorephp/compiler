<?php
// CSP channels over the scheduler, spelled in PHP: `foreach` for consumption,
// an object for the comma-ok receive, a typed exception for send-after-close,
// and select() over both directions with blocking / non-blocking / deadline forms.

use function Async\async;
use function Async\spawn;
use function Async\channel;
use function Async\select;
use function Async\selectNow;
use function Async\selectWithin;
use function Async\delay;
use Async\ChannelClosedException;
use Async\SelectCase;
use Async\TaskGroup;

async(function () {
    // Unbuffered: every send is a rendezvous. foreach ends at close.
    $ch = channel();
    spawn(function () use ($ch) {
        for ($i = 1; $i <= 5; $i++) { $ch->send($i); }
        $ch->close();
    });
    $sum = 0;
    foreach ($ch as $v) { $sum = $sum + $v; }
    echo "unbuffered sum: ", $sum, "\n";

    // Buffered: the sender runs ahead until the buffer fills.
    $b = channel(3);
    spawn(function () use ($b) {
        foreach (["a", "b", "c", "d", "e"] as $s) { $b->send($s); }
        $b->close();
    });
    $out = "";
    foreach ($b as $v) { $out = $out . $v; }
    echo "buffered: ", $out, "\n";

    // next() distinguishes "closed" from a legitimate null payload; recv() cannot.
    $nul = channel(2);
    $nul->send(null);
    $nul->close();
    $first = $nul->next();
    $after = $nul->next();
    echo "next: ok=", $first->ok ? "1" : "0", " value-is-null=", $first->value === null ? "1" : "0",
         " then ok=", $after->ok ? "1" : "0", "\n";

    // Fan-in: several producers into one channel, closed once all are done.
    $fan = channel(2);
    spawn(function () use ($fan) {
        TaskGroup::run(function (TaskGroup $g) use ($fan) {
            for ($p = 1; $p <= 3; $p++) {
                $g->spawn(function () use ($fan, $p) {
                    for ($i = 0; $i < 4; $i++) { $fan->send($p * 5); }
                });
            }
        });
        $fan->close();
    });
    $total = 0;
    foreach ($fan as $v) { $total = $total + $v; }
    echo "fan-in total: ", $total, "\n";

    // select(): whichever channel delivers first wins, exactly once.
    $x = channel();
    $y = channel();
    spawn(function () use ($x) { $x->send(4); });
    spawn(function () use ($y) { $y->send(6); });
    $got = 0;
    for ($i = 0; $i < 2; $i++) {
        $r = select([$x, $y]);
        $got = $got + $r->value;
    }
    echo "select sum: ", $got, "\n";

    // A send case: select parks on the send until a receiver shows up.
    $s = channel();
    spawn(function () use ($s) { delay(0.01); echo "select send got: ", $s->recv(), "\n"; });
    $sr = select([SelectCase::send($s, 99)]);
    echo "select send: index=", $sr->index, " isSend=", $sr->isSend ? "1" : "0", "\n";

    // Non-blocking form: nothing ready.
    $idle = channel();
    echo "selectNow idle: ", selectNow([$idle]) === null ? "null" : "value", "\n";
    $ready = channel(1);
    $ready->send(7);
    $now = selectNow([$idle, $ready]);
    echo "selectNow ready: index=", $now->index, " value=", $now->value, "\n";

    // Deadline form: expires with nothing delivered.
    echo "selectWithin: ", selectWithin(0.02, [$idle]) === null ? "timeout" : "value", "\n";

    // Sending on a closed channel is a typed exception, not a bare RuntimeException.
    $dead = channel(1);
    $dead->close();
    try {
        $dead->send(1);
        echo "closed send: MISSING\n";
    } catch (ChannelClosedException $e) {
        echo "closed send: ", $e->getMessage(), "\n";
    }
    echo "done\n";
});
