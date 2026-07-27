<?php

// stream_select under the scheduler parks on the REACTOR, it does not poll.
//
// The old implementation called poll(2) with a zero timeout and slept the fiber
// with an exponential backoff (0.2 ms → 10 ms), so a 200 ms wait cost ~15 timer
// wake-ups and up to 10 ms of latency per readiness edge. The reactor path costs
// ONE wake. `Async\stats()` is what makes the difference assertable.

use function Async\async;
use function Async\spawn;

$port = 0;
$server = false;
for ($p = 51500; $p < 51580; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) { $server = $s; $port = $p; break; }
}

$out = async(function () use ($server, $port): string {
    $srv = spawn(function () use ($server): string {
        $c = stream_socket_accept($server, 2.0);
        if ($c === false) { return 'no-conn'; }
        \Async\delay(0.2);                 // long enough that a poller would spin
        fwrite($c, 'ping');
        \Async\delay(0.05);
        fclose($c);
        return 'sent';
    });
    $sel = spawn(function () use ($port): string {
        $c = fsockopen('127.0.0.1', $port);
        if ($c === false) { return 'no-conn'; }
        $before = \Async\stats()['timer_fires'];
        $r = [$c]; $w = null; $e = null;
        $n = stream_select($r, $w, $e, 2, 0);
        $spins = \Async\stats()['timer_fires'] - $before;
        $data = $n > 0 ? fread($c, 16) : '';
        fclose($c);
        // A backoff poller needs ~15 timer fires to cross 200 ms; the reactor
        // path needs none of its own (the peer's two delays are the only timers,
        // and they belong to the other task).
        return 'n=' . (string)$n . ' data=' . $data
             . ' reactor=' . ($spins <= 4 ? 'yes' : 'no(' . (string)$spins . ')');
    })->named('selector');
    $a = $sel->await();
    $srv->await();
    return $a;
});
echo $out, "\n";

// A select that is still parked shows up in the dump as what it is.
$shape = async(function () use ($server): string {
    $t = spawn(function () use ($server): int {
        $r = [$server]; $w = null; $e = null;
        return (int)stream_select($r, $w, $e, 0, 120000);
    })->named('parked');
    \Async\delay(0.03);
    $d = \Async\dump();
    $n = $t->await();
    return 'n=' . (string)$n
         . ' shape=' . (strpos($d, '"parked"') !== false && strpos($d, 'select(1 fds) +deadline') !== false ? 'ok' : 'bad');
});
echo $shape, "\n";
fclose($server);
