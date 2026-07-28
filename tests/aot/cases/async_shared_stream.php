<?php

// TWO tasks reading ONE stream. The scheduler keeps a per-fd waiter CHAIN:
// a single-Task slot silently overwrote the first parker, so its fread() returned
// empty while the other task got the data (r1= / r2=hello). Both must be served.
// TWO readers on ONE stream: the per-fd waiter slot is a single Task, so the
// second park OVERWRITES the first and the first task is never woken.
use function Async\async; use function Async\spawn;
$port=0;$server=false;
for($p=50500;$p<50580;$p=$p+1){ $s=@stream_socket_server('tcp://127.0.0.1:'.$p); if($s!==false){$server=$s;$port=$p;break;} }
$r = async(function () use ($server,$port): string {
    $srv = spawn(function () use ($server): string {
        $c = stream_socket_accept($server, 2.0);
        if ($c === false) { return 'no-conn'; }
        \Async\delay(0.15);
        fwrite($c, "hello");
        \Async\delay(0.15);
        fwrite($c, "world");
        \Async\delay(0.1);
        fclose($c);
        return 'sent';
    });
    $cli = spawn(function () use ($port): string {
        $c = fsockopen('127.0.0.1', $port);
        if ($c === false) { return 'no'; }
        // Two tasks reading the SAME resource.
        $r1 = spawn(function () use ($c): string { $d = fread($c, 5); return $d === false ? 'FAIL' : $d; });
        $r2 = spawn(function () use ($c): string { $d = fread($c, 5); return $d === false ? 'FAIL' : $d; });
        // WHICH reader gets which half is not promised — the per-fd waiter chain is
        // LIFO and the reactor decides the wake order (kqueue and epoll need not
        // agree). What IS promised: both readers are served and between them they see
        // every byte exactly once, so assert the SET, not an interleaving. Awaited one
        // at a time (no array indexing) and reported verbatim on a mismatch, so a
        // failure on another platform says what it actually saw.
        $one = $r1->await();
        $two = $r2->await();
        fclose($c);
        $ok = (($one === 'hello' && $two === 'world')
            || ($one === 'world' && $two === 'hello'));
        return $ok ? 'both-halves' : ('unexpected[' . $one . '|' . $two . ']');
    });
    return $srv->await() . ' ' . $cli->await();
});
echo $r, "\n";
