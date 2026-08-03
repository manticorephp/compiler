<?php

// MANTICORE-ONLY (no Http\Server in php): every refusal, over a real socket.
//
// These are the paths an unauthenticated peer can reach, so each one has to
// answer a precomputed response and CLOSE — a limit that leaves the connection
// open is not a limit. The idle case is the odd one: nothing is written at all,
// which is what nginx does to a client that connects and says nothing.
// The expected output is written BY HAND.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 49860; $p < 49940; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) {
        $listener = $s;
        $port = $p;
        break;
    }
}
if ($listener === false) {
    echo "no free port\n";
    return;
}
stream_set_blocking($listener, false);

/** Send $raw on a fresh connection; answer "status | rest-of-connection". */
function probe(int $port, string $label, string $raw): void
{
    $c = fsockopen('127.0.0.1', $port);
    if ($c === false) {
        echo $label, ': CONNECT-FAILED', "\n";
        return;
    }
    fwrite($c, $raw);
    $buf = '';
    while (true) {
        $part = fread($c, 4096);
        if ($part === '') {
            break;
        }
        $buf .= $part;
    }
    fclose($c);
    if ($buf === '') {
        echo $label, ": CLOSED-SILENTLY\n";
        return;
    }
    $end = strpos($buf, "\r\n\r\n");
    $head = $end === false ? $buf : substr($buf, 0, $end);
    $lines = explode("\r\n", $head);
    $conn = '?';
    $len = '?';
    foreach ($lines as $i => $line) {
        if ($i === 0) {
            continue;
        }
        if (stripos($line, 'connection:') === 0) {
            $conn = trim(substr($line, 11));
        }
        if (stripos($line, 'content-length:') === 0) {
            $len = trim(substr($line, 15));
        }
    }
    $tail = $end === false ? '' : substr($buf, $end + 4);
    echo $label, ': ', $lines[0], ' conn=', $conn, ' len=', $len,
        ' after=', strlen($tail), "\n";
}

$server = \Http\Server::onListener($listener)
    ->serverName('')
    ->maxBodySize(32)
    ->maxHeaderBytes(512)
    ->maxHeaderCount(8)
    ->idleTimeout(0.3)
    ->headerTimeout(0.3)
    ->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            return (new \Http\Response())->text('ok:' . strlen($req->body()));
        });
    });

    \Async\delay(0.05);

    // A body that fits still works — the control for everything below.
    probe($port, 'ok', "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 4\r\n\r\nabcd");

    // Declared past maxBodySize: refused before a byte of it is read.
    probe($port, 'big-declared', "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 100\r\n\r\n");

    // Chunked has no declared total, so the cap is applied as it accumulates.
    probe($port, 'big-chunked', "POST / HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n"
        . "10\r\n0123456789abcdef\r\n10\r\n0123456789abcdef\r\n10\r\n0123456789abcdef\r\n0\r\n\r\n");

    // Too many header FIELDS…
    $many = '';
    for ($i = 0; $i < 20; $i++) {
        $many .= 'X-H' . $i . ": v\r\n";
    }
    probe($port, 'header-count', "GET / HTTP/1.1\r\nHost: t\r\n" . $many . "\r\n");

    // …and too many header BYTES, which is the one a single huge line hits.
    probe($port, 'header-bytes', "GET / HTTP/1.1\r\nHost: t\r\nX-Big: "
        . str_repeat('z', 900) . "\r\n\r\n");

    // A malformed request line.
    probe($port, 'bad-line', "GET\r\nHost: t\r\n\r\n");

    // A body-bearing method with no framing at all.
    probe($port, 'no-length', "POST / HTTP/1.1\r\nHost: t\r\n\r\n");

    // A version this server does not speak.
    probe($port, 'version', "GET / HTTP/2.0\r\nHost: t\r\n\r\n");

    // 1.1 without Host.
    probe($port, 'no-host', "GET / HTTP/1.1\r\n\r\n");

    // Transfer-Encoding AND Content-Length — the smuggling shape.
    probe($port, 'te-and-cl', "POST / HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n"
        . "Content-Length: 4\r\n\r\n");

    // A client that connects and says nothing is closed SILENTLY on the idle
    // timeout — no bytes, no status line.
    probe($port, 'idle', '');

    // One that starts a head and stops half-way ran out its clock mid-request.
    probe($port, 'half-head', "GET / HTTP/1.1\r\nHost: t\r\n");

    $server->stop();
    $st = $server->stats();
    echo 'served=', $st['served'], ' errors=', $st['errors'], "\n";
});

echo "done\n";
