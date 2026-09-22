<?php

// MANTICORE-ONLY (no Http\Server in php). A kept-alive connection that keeps
// talking must outlive idleTimeout: the idle clock is PER REQUEST, from the
// end of the previous one. The scheduler's timer heap deletes lazily, keyed
// on the task's `timerActive` flag alone — so the bounded wait of the FIRST
// read left its slot in the heap, the task re-armed for the next read, and
// the stale slot read as live again and fired at its OLD deadline: the
// connection closed exactly idleTimeout after accept, in the middle of a
// request the client was still sending on time. Six requests 0.1 s apart on
// a 0.25 s idle timeout: with the stale slot the third one meets a closed
// socket.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 52900; $p < 52980; $p = $p + 1) {
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

/** One response off a keep-alive socket; '' when the peer has gone. */
function answer(\Resource $c): string
{
    $buf = '';
    while (true) {
        $end = strpos($buf, "\r\n\r\n");
        if ($end !== false) {
            $len = 0;
            foreach (explode("\r\n", substr($buf, 0, $end)) as $line) {
                if (stripos($line, 'content-length:') === 0) {
                    $len = (int)trim(substr($line, 15));
                }
            }
            if (strlen($buf) >= $end + 4 + $len) {
                return substr($buf, $end + 4, $len);
            }
        }
        $chunk = fread($c, 4096);
        if ($chunk === '') {
            return '';
        }
        $buf .= $chunk;
    }
}

$server = \Http\Server::onListener($listener)
    ->serverName('')
    ->idleTimeout(0.25)
    ->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            return (new \Http\Response())->text('n=' . $req->query('n', '?'));
        });
    });

    \Async\delay(0.05);

    $c = fsockopen('127.0.0.1', $port);
    if ($c === false) {
        echo "connect failed\n";
        return;
    }
    for ($i = 1; $i <= 6; $i = $i + 1) {
        \Async\delay(0.1);
        $n = fwrite($c, "GET /?n=" . $i . " HTTP/1.1\r\nHost: t\r\n\r\n");
        $got = $n === false ? 'write failed' : answer($c);
        echo $i, ': ', $got === '' ? 'CLOSED' : $got, "\n";
    }
    fclose($c);

    // Silence past the timeout is still the close it always was.
    $c = fsockopen('127.0.0.1', $port);
    if ($c !== false) {
        \Async\delay(0.4);
        echo 'idle: ', fread($c, 64) === '' ? 'closed' : 'open', "\n";
        fclose($c);
    }

    $server->stop();
    echo 'served=', $server->stats()['served'], "\n";
});

echo "done\n";
