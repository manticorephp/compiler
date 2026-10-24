<?php

use Async\SelectCase;
use function Async\async;
use function Async\channel;
use function Async\delay;
use function Async\select;
use function Async\selectNow;
use function Async\selectWithin;
use function Async\spawn;

async(function () {
    $fast = channel();
    $slow = channel();

    spawn(function () use ($fast) { delay(0.01); $fast->send("from fast"); });
    spawn(function () use ($slow) { delay(0.05); $slow->send("from slow"); });

    // A bare channel is shorthand for a receive case.
    $r = select([$slow, $fast]);
    echo "case {$r->index}: {$r->value}\n";

    // Nothing is ready right now.
    var_dump(selectNow([$fast]));

    // A deadline: null when it expires first.
    var_dump(selectWithin(0.01, [$fast]));

    // The other value is still on its way.
    $r = select([$slow]);
    echo $r->value, "\n";

    // A send arm completes when a receiver (or buffer space) is available.
    $out = channel(1);
    $r = select([SelectCase::send($out, 7)]);
    echo $r->isSend ? "sent " : "received ", $out->recv(), "\n";
});
