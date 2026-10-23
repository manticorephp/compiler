<?php

use Async\ChannelClosedException;
use function Async\async;
use function Async\channel;
use function Async\spawn;

async(function () {
    // Capacity 0 is a rendezvous: send() waits for a receiver.
    $ch = channel();

    spawn(function () use ($ch) {
        foreach ([1, 2, 3] as $n) {
            $ch->send($n);
        }
        $ch->close();
    });

    // foreach ends once the channel is closed and drained.
    foreach ($ch as $value) {
        echo "got $value\n";
    }

    // next() is the explicit form: ok is false on a closed channel,
    // which lets null be a legitimate payload.
    $r = $ch->next();
    echo $r->ok ? "value\n" : "closed\n";

    try {
        $ch->send(4);
    } catch (ChannelClosedException $e) {
        echo "send failed: ", $e->getMessage(), "\n";
    }

    // A buffered channel takes values without a waiting receiver.
    $buf = channel(2);
    $buf->send("a");
    $buf->send("b");
    echo $buf->recv(), $buf->recv(), "\n";
});
