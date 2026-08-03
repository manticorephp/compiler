<?php

// MANTICORE-ONLY (no Http\Server in php): a STREAMED response body — the
// length is not knowable when the head goes out, so it is framed chunked and
// the client de-chunks it. 40 KiB crosses several reads on both sides, which
// is the point: a body that fit in one write would prove nothing.
// The expected output is written BY HAND.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 49540; $p < 49620; $p = $p + 1) {
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

$server = \Http\Server::onListener($listener)->serverName('')->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            if ($req->path === '/big') {
                // 40 KiB in 640 writes — many chunks, several socket writes.
                return (new \Http\Response())->type('text/plain')
                    ->stream(function (\Http\ChunkedWriter $w): void {
                        for ($i = 0; $i < 640; $i++) {
                            $w->write(str_repeat('ab', 32));
                        }
                    });
            }
            if ($req->path === '/few') {
                return (new \Http\Response())->stream(function (\Http\ChunkedWriter $w): void {
                    $w->write('one-');
                    $w->flush();
                    $w->write('two-');
                    $w->write('three');
                });
            }
            if ($req->path === '/empty') {
                return (new \Http\Response())->stream(function (\Http\ChunkedWriter $w): void {
                });
            }
            if ($req->path === '/boom') {
                // A closure that throws mid-body: the framing must still be
                // terminated, or the peer waits for a body that never ends.
                return (new \Http\Response())->stream(function (\Http\ChunkedWriter $w): void {
                    $w->write('partial');
                    throw new \RuntimeException('mid-stream');
                });
            }
            return (new \Http\Response(404))->text('nope');
        });
    });

    \Async\delay(0.05);

    $c = fsockopen('127.0.0.1', $port);
    fwrite($c, "GET /few HTTP/1.1\r\nHost: t\r\n\r\n");
    echo 'few: ', readChunked($c), "\n";

    fwrite($c, "GET /empty HTTP/1.1\r\nHost: t\r\n\r\n");
    echo 'empty: ', readChunked($c), "\n";

    fwrite($c, "GET /big HTTP/1.1\r\nHost: t\r\n\r\n");
    $big = readChunked($c);
    $bar = strpos($big, '|');
    $body = substr($big, $bar + 1);
    echo 'big: ', substr($big, 0, $bar), ' len=', strlen($body),
        ' head=', substr($body, 0, 8), ' tail=', substr($body, strlen($body) - 8), "\n";
    fclose($c);

    // An HTTP/1.0 peer has no chunked encoding: the bytes go raw and the close
    // is the framing.
    $c2 = fsockopen('127.0.0.1', $port);
    fwrite($c2, "GET /few HTTP/1.0\r\n\r\n");
    $raw = '';
    while (true) {
        $part = fread($c2, 4096);
        if ($part === '') {
            break;
        }
        $raw .= $part;
    }
    $e = strpos($raw, "\r\n\r\n");
    $h10 = substr($raw, 0, $e);
    echo 'http10: ', explode("\r\n", $h10)[0],
        ' chunked=', stripos($h10, 'chunked') === false ? 'no' : 'yes',
        ' conn=', stripos($h10, 'Connection: close') === false ? '?' : 'close',
        ' body=', substr($raw, $e + 4), "\n";
    fclose($c2);

    // The thrower: the head is already on the wire when it fails, so there is
    // no 500 to send — the body just ends, correctly framed.
    $c3 = fsockopen('127.0.0.1', $port);
    fwrite($c3, "GET /boom HTTP/1.1\r\nHost: t\r\n\r\n");
    echo 'boom: ', readChunked($c3), "\n";
    fclose($c3);

    $server->stop();
    echo 'served=', $server->stats()['served'], "\n";
});

echo "done\n";
