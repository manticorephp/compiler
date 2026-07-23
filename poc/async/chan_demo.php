<?php

// Channels (CSP) over the async runtime: unbuffered rendezvous, buffered, fan-in.

use function Async\{run, spawn, channel};
use Async\TaskGroup;

run(function () {
    // Unbuffered rendezvous: producer hands off 1..5, consumer sums, close ends it.
    $ch = channel();
    spawn(function () use ($ch) {
        for ($i = 1; $i <= 5; $i++) {
            $ch->send($i);
        }
        $ch->close();
    });
    $sum = 0;
    while (($v = $ch->recv()) !== null) {
        $sum += $v;
    }
    echo "unbuffered sum: ", $sum, "\n";

    // Buffered (cap 3): a single producer overfills; FIFO order is preserved.
    $b = channel(3);
    spawn(function () use ($b) {
        foreach (["a", "b", "c", "d", "e"] as $x) {
            $b->send($x);
        }
        $b->close();
    });
    $out = "";
    while (($v = $b->recv()) !== null) {
        $out .= $v;
    }
    echo "buffered: ", $out, "\n";

    // Fan-in: 3 workers push into one channel; a coordinator joins them then closes.
    $c = channel();
    spawn(function () use ($c) {
        TaskGroup::run(function (TaskGroup $g) use ($c) {
            for ($w = 1; $w <= 3; $w++) {
                $g->spawn(function () use ($c, $w) {
                    $c->send($w * 10);
                });
            }
        });
        $c->close();
    });
    $total = 0;
    while (($v = $c->recv()) !== null) {
        $total += $v;
    }
    echo "fan-in total: ", $total, "\n";
});
