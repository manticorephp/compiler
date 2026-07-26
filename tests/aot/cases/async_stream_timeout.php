<?php

// Bounded waits under a scheduler (the 4th AsyncHook slot).
//
// Two holes this covers, both of which used to park a fiber FOREVER:
//   1. stream_set_timeout() was silently ignored under async — a peer that
//      accepts and then never writes wedged the reading fiber, and
//      stream_get_meta_data()['timed_out'] could never become true.
//   2. stream_socket_accept($timeout) fell into the synchronous branch, whose
//      poll(2) blocked the WHOLE scheduler instead of just the caller.
//
// The port is scanned rather than read back — see net_tcp_loopback for why.

use function Async\async;
use function Async\spawn;

$port = 0;
$server = false;
for ($p = 49380; $p < 49460; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) {
        $server = $s;
        $port = $p;
        break;
    }
}
var_dump($server !== false);

$report = async(function () use ($server, $port): string {
    // A silent peer: accept the connection and never write to it.
    $silent = spawn(function () use ($server): string {
        $conn = stream_socket_accept($server);
        if ($conn === false) {
            return 'no-conn';
        }
        // Hold it open past the reader's deadline, then close.
        \Async\delay(0.4);
        fclose($conn);
        return 'held';
    });

    // The reader gives up after 120 ms instead of parking forever.
    $reader = spawn(function () use ($port): string {
        $c = fsockopen('127.0.0.1', $port);
        if ($c === false) {
            return 'no-connect';
        }
        stream_set_timeout($c, 0, 120000);
        $t0 = microtime(true);
        $data = fread($c, 16);
        $took = microtime(true) - $t0;
        $meta = stream_get_meta_data($c);
        fclose($c);
        return 'read=' . (($data === '' || $data === false) ? 'empty' : 'data')
            . ' timed_out=' . ($meta['timed_out'] ? 'yes' : 'no')
            . ' bounded=' . ($took < 1.0 ? 'yes' : 'no');
    });

    // ...and the loop keeps running other work while it waits.
    $ticker = spawn(function (): int {
        $n = 0;
        for ($i = 0; $i < 4; $i = $i + 1) {
            \Async\delay(0.02);
            $n = $n + 1;
        }
        return $n;
    });

    $r = $reader->await();
    $ticks = $ticker->await();
    $silent->await();
    return $r . ' ticks=' . (string)$ticks;
});
echo $report, "\n";

// accept() with a deadline and nobody connecting: false, and the scheduler stays
// live throughout (the sibling task keeps ticking).
$acc = async(function () use ($server): string {
    $waiter = spawn(function () use ($server): string {
        $t0 = microtime(true);
        $conn = stream_socket_accept($server, 0.1);
        $took = microtime(true) - $t0;
        return ($conn === false ? 'false' : 'conn')
            . ' bounded=' . ($took < 1.0 ? 'yes' : 'no');
    });
    $ticker = spawn(function (): int {
        $n = 0;
        for ($i = 0; $i < 3; $i = $i + 1) {
            \Async\delay(0.01);
            $n = $n + 1;
        }
        return $n;
    });
    $r = $waiter->await();
    return $r . ' ticks=' . (string)$ticker->await();
});
echo $acc, "\n";

fclose($server);
echo "done\n";
