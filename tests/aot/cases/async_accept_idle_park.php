<?php

// The accept loop must PARK on an idle listener, not spin — the regression guard on
// the classified rewrite (two branches, bounded and unbounded, folded into one).
//
// A lost wake would hang this; a spin would show as a wakes counter climbing with
// wall time instead of with connections. Both branches are exercised: an unbounded
// accept that is eventually satisfied, and a bounded one that expires.
//
// ⚠ Nothing printed before the first async(): php cannot run this (no Io\Poll), and
// difftest classifies a file as manticore-only only when php writes NO stdout.

use function Async\async;
use function Async\spawn;

$port = 0;
$server = false;
for ($p = 49660; $p < 49740; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) {
        $server = $s;
        $port = $p;
        break;
    }
}

$report = async(function () use ($server, $port): string {
    $before = \Async\stats()['wakes'];

    // Unbounded accept: parked for ~250 ms of wall time before a client shows up.
    // A spinning loop would burn thousands of wakes over that stretch.
    $acceptor = spawn(function () use ($server): string {
        $conn = stream_socket_accept($server);
        if ($conn === false) {
            return 'no-conn';
        }
        fclose($conn);
        return 'accepted';
    });
    $client = spawn(function () use ($port): string {
        \Async\delay(0.25);
        $c = fsockopen('127.0.0.1', $port);
        if ($c === false) {
            return 'no-connect';
        }
        fclose($c);
        return 'connected';
    });
    $ticker = spawn(function (): int {
        $n = 0;
        for ($i = 0; $i < 5; $i = $i + 1) {
            \Async\delay(0.02);
            $n = $n + 1;
        }
        return $n;
    });

    $a = $acceptor->await();
    $c = $client->await();
    $t = $ticker->await();
    $wakes = \Async\stats()['wakes'] - $before;
    return $a . ' ' . $c . ' ticks=' . (string)$t
        . ' parked=' . ($wakes < 200 ? 'yes' : 'no');
});
echo "listening: ", ($server !== false ? 'yes' : 'no'), "\n";
echo $report, "\n";

// The bounded branch of the same loop still expires, and still leaves the scheduler
// live (the deadline is absolute — a re-park after a lost race cannot extend it).
$bounded = async(function () use ($server): string {
    $waiter = spawn(function () use ($server): string {
        $t0 = microtime(true);
        $conn = stream_socket_accept($server, 0.15);
        return ($conn === false ? 'false' : 'conn')
            . ' bounded=' . ((microtime(true) - $t0) < 1.0 ? 'yes' : 'no');
    });
    $ticker = spawn(function (): int {
        $n = 0;
        for ($i = 0; $i < 3; $i = $i + 1) {
            \Async\delay(0.01);
            $n = $n + 1;
        }
        return $n;
    });
    return $waiter->await() . ' ticks=' . (string)$ticker->await();
});
echo $bounded, "\n";

fclose($server);
echo "done\n";
