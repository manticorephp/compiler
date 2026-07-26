<?php
// Go-style CSP channels over the scheduler: unbuffered rendezvous, a buffered
// FIFO, fan-in, close(), and select() over several receives.

use function Async\async;
use function Async\spawn;
use function Async\channel;
use function Async\select;
use Async\TaskGroup;

async(function () {
    // Unbuffered: every send is a rendezvous with the receiver.
    $ch = channel();
    spawn(function () use ($ch) {
        for ($i = 1; $i <= 5; $i++) { $ch->send($i); }
        $ch->close();
    });
    $sum = 0;
    while (($v = $ch->recv()) !== null) { $sum = $sum + $v; }
    echo "unbuffered sum: ", $sum, "\n";

    // Buffered: the sender runs ahead until the buffer fills.
    $b = channel(3);
    spawn(function () use ($b) {
        foreach (["a", "b", "c", "d", "e"] as $s) { $b->send($s); }
        $b->close();
    });
    $out = "";
    while (($v = $b->recv()) !== null) { $out = $out . $v; }
    echo "buffered: ", $out, "\n";

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
    while (($v = $fan->recv()) !== null) { $total = $total + $v; }
    echo "fan-in total: ", $total, "\n";

    // select(): whichever channel delivers first wins, exactly once.
    $x = channel();
    $y = channel();
    spawn(function () use ($x) { $x->send(4); });
    spawn(function () use ($y) { $y->send(6); });
    $got = 0;
    for ($i = 0; $i < 2; $i++) {
        $r = select([$x, $y]);
        $got = $got + $r[1];
    }
    echo "select sum: ", $got, "\n";
    echo "done\n";
});
