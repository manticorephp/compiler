<?php

// MANTICORE-ONLY (php has no Async\ scheduler). Pin for round-1 review item #1
// on the wss:// loopback fix: the plain-socket "bulk bypass" in
// __mc_stream_read (fread() >= 4096 bytes) used to skip __mc_stream_recv_into's
// KIND_SOCKET-under-AsyncHook retry loop entirely, going straight to a single
// wait-then-one-recv attempt instead.
//
// Two fibers fread() the SAME non-blocking fd at once, both chained as read
// waiters on one fd (Scheduler::chainRead in prelude/async.php wakes the WHOLE
// chain on one readability event, not just one waiter). When the first chunk
// lands, both wake; one recv() wins the bytes, the other's recv is a real,
// unforced EWOULDBLOCK straight off a genuine wake — not a contrived error.
// Before the fix that loser's fread() reported '' (0 bytes) instead of parking
// again for the second chunk, silently losing it. With the fix it retries and
// gets its share once the second chunk arrives.
//
// ASSUMES the first 5000 B server write arrives as ONE recv() on the client
// side (loopback, well under the MTU/socket-buffer size, so this has been
// reliable in practice) — split=uneven would also fire on a kernel that
// hands it back in two pieces, a false positive this case does not try to
// tell apart from the real regression it pins.

use function Async\async;
use function Async\spawn;

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);

async(function () use ($l, $port) {
    spawn(function () use ($l) {
        $c = stream_socket_accept($l, 5.0);
        stream_set_blocking($c, false);
        // A generous head start: both readers below must be fully registered
        // as chained waiters on the client fd before ANY byte arrives, so the
        // wake-both/one-wins race is forced rather than left to luck.
        \Async\delay(0.05);
        fwrite($c, str_repeat('a', 5000));
        \Async\delay(0.1);
        fwrite($c, str_repeat('b', 5000));
        fclose($c);
    });

    $errno = 0;
    $errstr = '';
    $c = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5.0);
    stream_set_blocking($c, false);

    $r1 = spawn(function () use ($c) { return strlen(fread($c, 6000)); });
    $r2 = spawn(function () use ($c) { return strlen(fread($c, 6000)); });

    $n1 = $r1->await();
    $n2 = $r2->await();
    echo 'total=', $n1 + $n2, "\n";
    echo 'split=', ($n1 === 5000 && $n2 === 5000) ? 'even' : 'uneven', "\n";

    fclose($c);
    fclose($l);
});
