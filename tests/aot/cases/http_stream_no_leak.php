<?php

// MANTICORE-ONLY (no Http\Server in php). A STREAMED response goes out through
// `Buffer\Writer`: the body is queued in `$this->pending` and flushed with
// `fwrite($this->dst, [$this->pending])`, then `$this->pending = ''`. That
// reset is the slot's release-before-overwrite; with the read judged a borrow
// the slot never dropped, and every flush stranded its buffer.
//
// memory_get_usage() answers the peak RSS (ru_maxrss), which a leak can only
// raise: one keep-alive client drives 200 warm-up streamed GETs, then 5 000
// measured ones, and the growth over the measured run is the pin — peak RSS,
// so allocator slack is inside the bound. @serial: a memory measurement, not
// a race with nine other cases.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 52800; $p < 52880; $p = $p + 1) {
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

/** Read a head, then de-chunk the body. Answers "status|body". */
function readChunked(\Resource $c): string
{
    $buf = '';
    while (strpos($buf, "\r\n\r\n") === false) {
        $part = fread($c, 4096);
        if ($part === '') {
            return 'TRUNCATED-HEAD|';
        }
        $buf .= $part;
    }
    $end = strpos($buf, "\r\n\r\n");
    $head = substr($buf, 0, $end);
    $rest = substr($buf, $end + 4);
    $lines = explode("\r\n", $head);
    $te = '';
    $cl = -1;
    foreach ($lines as $i => $line) {
        if ($i === 0) {
            continue;
        }
        if (stripos($line, 'transfer-encoding:') === 0) {
            $te = strtolower(trim(substr($line, 18)));
        }
        if (stripos($line, 'content-length:') === 0) {
            $cl = (int)trim(substr($line, 15));
        }
    }
    if ($te !== 'chunked') {
        return $lines[0] . '|NOT-CHUNKED cl=' . $cl;
    }
    $body = '';
    while (true) {
        $nl = strpos($rest, "\r\n");
        while ($nl === false) {
            $part = fread($c, 4096);
            if ($part === '') {
                return $lines[0] . '|TRUNCATED-SIZE';
            }
            $rest .= $part;
            $nl = strpos($rest, "\r\n");
        }
        $size = hexdec(trim(substr($rest, 0, $nl)));
        $rest = substr($rest, $nl + 2);
        if ($size === 0) {
            return $lines[0] . '|' . $body;
        }
        while (strlen($rest) < $size + 2) {
            $part = fread($c, 4096);
            if ($part === '') {
                return $lines[0] . '|TRUNCATED-DATA';
            }
            $rest .= $part;
        }
        $body .= substr($rest, 0, $size);
        $rest = substr($rest, $size + 2);
    }
}

$server = \Http\Server::onListener($listener)
    ->serverName('')
    ->keepAliveMax(100000)
    ->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            return (new \Http\Response())->type('text/plain')
                ->stream(function (\Http\ChunkedWriter $w): void {
                    for ($i = 0; $i < 8; $i++) {
                        $w->write(str_repeat('ab', 96));
                        $w->flush();
                    }
                });
        });
    });

    \Async\delay(0.05);

    $c = fsockopen('127.0.0.1', $port);
    if ($c === false) {
        echo "connect failed\n";
        return;
    }
    $raw = "GET /s HTTP/1.1\r\nHost: t\r\n\r\n";
    fwrite($c, $raw);
    $first = readChunked($c);
    echo 'first=', substr($first, 0, 15), ' len=', strlen($first) - 16, "\n";

    for ($i = 0; $i < 200; $i = $i + 1) {
        fwrite($c, $raw);
        readChunked($c);
    }
    $before = memory_get_usage();
    $n = 5000;
    $bad = 0;
    for ($i = 0; $i < $n; $i = $i + 1) {
        fwrite($c, $raw);
        if (strlen(readChunked($c)) !== 1552) {
            $bad = $bad + 1;
        }
    }
    $growth = memory_get_usage() - $before;
    fclose($c);

    echo 'bad=', $bad, "\n";
    if ($growth < 3 * 1024 * 1024) {
        echo "growth ok\n";
    } else {
        echo 'growth=', round($growth / 1048576, 1), "MB over ", $n, " requests\n";
    }
    $server->stop();
    echo 'served=', $server->stats()['served'], "\n";
});

echo "done\n";
