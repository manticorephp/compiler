<?php

// The WRITE side of a bounded wait — the twin of async_stream_timeout.
//
// Every write path took the UNBOUNDED writable slot, so a peer that accepts and
// then never reads (a zero TCP window, the reverse-slowloris) parked the writing
// fiber forever: the task never settled, its scope never closed, the fd was never
// released, and nothing said why. stream_set_timeout() now bounds writes too — as
// in php, where the value is the STREAM's timeout, not the read's — and expiry is
// a SHORT WRITE with timed_out set, never an exception.
//
// The buffers are shrunk on both ends (SO_RCVBUF on the listener, inherited by the
// accepted fd; SO_SNDBUF on the client) so the send window fills in well under the
// megabyte written, on any host.
//
// NOT covered here: a TLS peer that stalls mid-record, which the same change bounds
// in __mc_stream_fill. It is not reproducible offline — an SSL_read that returns
// WANT_READ forever needs a hostile server — so that half rides on review plus
// async_tls_loopback staying green.
//
// ⚠ Nothing may be printed before the first async() call: difftest treats a file as
// manticore-only when php produces NO stdout, and php cannot run this (no Io\Poll).

use function Async\async;
use function Async\spawn;

$port = 0;
$server = false;
for ($p = 49560; $p < 49640; $p = $p + 1) {
    $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($sock === false) {
        continue;
    }
    socket_set_option($sock, SOL_SOCKET, SO_RCVBUF, 4096);
    if (@socket_bind($sock, '127.0.0.1', $p) && @socket_listen($sock, 8)) {
        $server = socket_export_stream($sock);
        $port = $p;
        break;
    }
    socket_close($sock);
}

$report = async(function () use ($server, $port): string {
    // A deaf peer: accept, then never read a byte, and hold the connection open
    // past the writer's deadline.
    $deaf = spawn(function () use ($server): string {
        $conn = stream_socket_accept($server, 2.0);
        if ($conn === false) {
            return 'no-conn';
        }
        \Async\delay(1.2);
        fclose($conn);
        return 'held';
    });

    $writer = spawn(function () use ($port): string {
        $c = fsockopen('127.0.0.1', $port);
        if ($c === false) {
            return 'no-connect';
        }
        $cs = socket_import_stream($c);
        if ($cs !== false) {
            socket_set_option($cs, SOL_SOCKET, SO_SNDBUF, 4096);
        }
        stream_set_timeout($c, 0, 150000);
        $want = 1 << 20;
        $t0 = microtime(true);
        $n = fwrite($c, str_repeat('x', $want));
        $took = microtime(true) - $t0;
        $meta = stream_get_meta_data($c);
        fclose($c);
        return 'short=' . ($n < $want ? 'yes' : 'no')
            . ' timed_out=' . ($meta['timed_out'] ? 'yes' : 'no')
            . ' bounded=' . ($took < 2.0 ? 'yes' : 'no');
    });

    // ...and the loop keeps running other work throughout.
    $ticker = spawn(function (): int {
        $n = 0;
        for ($i = 0; $i < 4; $i = $i + 1) {
            \Async\delay(0.02);
            $n = $n + 1;
        }
        return $n;
    });

    $w = $writer->await();
    $ticks = $ticker->await();
    $deaf->await();
    return $w . ' ticks=' . (string)$ticks;
});
echo "listening: ", ($server !== false ? 'yes' : 'no'), "\n";
echo $report, "\n";

// stream_set_timeout is the stream's, not the read's: both directions carry it.
stream_set_timeout($server, 3);
echo 'r=', $server->rtimeoutMs, ' w=', $server->wtimeoutMs, "\n";

fclose($server);
echo "done\n";
