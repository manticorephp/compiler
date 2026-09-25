<?php

// MANTICORE-ONLY (no Http\Server in php); expected output written by hand.
// A 101 response hands the socket AND the bytes already read past the head to
// a takeover closure, in the connection's own fiber; the connection is never
// reused for HTTP after it. A stop hook reaches a live takeover, even one
// registered AFTER stop() already ran. A takeover that is not a clean
// 101-on-1.1, or that leaves an unread streamed body behind, is a handler
// bug: 500. A takeover survives the request's own (short) read deadline. An
// onError-returned takeover goes through the same checks as a handler's.

use function Async\async;
use function Async\spawn;

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);

$server = \Http\Server::onListener($l)
    ->acceptWait(0.02)
    ->serverName('')
    ->idleTimeout(0.3)
    ->headerTimeout(0.3)
    ->streamBodies(true)
    // Below the /bodyleak body's Content-Length, so THAT one body is handed
    // over as a Reader (over maxBodySize + streaming on) instead of buffered.
    ->maxBodySize(2)
    ->onError(function (\Throwable $e, \Http\Request $req): \Http\Response {
        return (new \Http\Response(101))
            ->header('Upgrade', 'x-shout')
            ->header('Connection', 'Upgrade')
            ->takeover(function (\Resource $c, \Buffer\ByteBuffer $b, \Http\Server $s): void {
                fwrite($c, "ERRUP\n");
            });
    });

function readUntil(\Resource $c, string $needle, string &$buf): string
{
    while (strpos($buf, $needle) === false) {
        $chunk = fread($c, 4096);
        if ($chunk === '' || $chunk === false) { return ''; }
        $buf .= $chunk;
    }
    $p = strpos($buf, $needle) + strlen($needle);
    $out = substr($buf, 0, $p);
    $buf = substr($buf, $p);
    return $out;
}

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            if ($req->path === '/bad') {
                return (new \Http\Response(200))->takeover(function ($c, $b, $s) { });
            }
            if ($req->path === '/bodyleak') {
                // A declared body the handler never reads — must not ride
                // into the new protocol as its first bytes.
                return (new \Http\Response(101))
                    ->header('Upgrade', 'x-shout')
                    ->takeover(function ($c, $b, $s) { });
            }
            if ($req->path === '/error') {
                throw new \RuntimeException('boom');
            }
            if ($req->path === '/lateStop') {
                return (new \Http\Response(101))
                    ->header('Upgrade', 'x-shout')
                    ->header('Connection', 'Upgrade')
                    ->takeover(function (\Resource $c, \Buffer\ByteBuffer $b, \Http\Server $s): void {
                        // Wait for the pin's "go" so onStop() is called AFTER
                        // the server's stop() already ran.
                        while (true) {
                            $nl = $b->indexOf("\n");
                            if ($nl >= 0) {
                                $b->read($nl + 1);
                                break;
                            }
                            $chunk = fread($c, 4096);
                            if ($chunk === '' || $chunk === false) { return; }
                            $b->append($chunk);
                        }
                        $s->onStop(function () use ($c): void { fwrite($c, "LATEBYE\n"); });
                    });
            }
            return (new \Http\Response(101))
                ->header('Upgrade', 'x-shout')
                ->header('Connection', 'Upgrade')
                ->takeover(function (\Resource $c, \Buffer\ByteBuffer $b, \Http\Server $s): void {
                    // A hook that throws must not keep the next one from running.
                    $s->onStop(function (): void { throw new \RuntimeException('hook boom'); });
                    $id = $s->onStop(function () use ($c): void { fwrite($c, "BYE\n"); });
                    try {
                        while (true) {
                            $nl = $b->indexOf("\n");
                            if ($nl >= 0) {
                                $line = $b->read($nl + 1);
                                fwrite($c, strtoupper($line));
                                continue;
                            }
                            $chunk = fread($c, 4096);
                            if ($chunk === '' || $chunk === false) { return; }
                            $b->append($chunk);
                        }
                    } finally {
                        $s->offStop($id);
                    }
                });
        });
    });

    $c = stream_socket_client('tcp://127.0.0.1:' . $port);
    $buf = '';
    // The first line rides the same write as the head.
    fwrite($c, "GET /shout HTTP/1.1\r\nHost: x\r\n\r\nhello\n");
    $head = readUntil($c, "\r\n\r\n", $buf);
    echo strtok($head, "\r\n"), "\n";
    echo str_contains(strtolower($head), "upgrade: x-shout") ? "upgrade header\n" : "no upgrade header\n";
    echo str_contains(strtolower($head), "content-length") ? "has content-length\n" : "no content-length\n";
    echo readUntil($c, "\n", $buf);
    fwrite($c, "again\n");
    echo readUntil($c, "\n", $buf);

    // The session outlives pump's own (short) read deadline: it does not
    // inherit headerTimeout/idleTimeout(0.3) after the takeover.
    usleep(500000);
    fwrite($c, "late\n");
    echo readUntil($c, "\n", $buf);

    $d = stream_socket_client('tcp://127.0.0.1:' . $port);
    fwrite($d, "GET /bad HTTP/1.1\r\nHost: x\r\n\r\n");
    $dbuf = '';
    echo strtok(readUntil($d, "\r\n\r\n", $dbuf), "\r\n"), "\n";
    fclose($d);

    $f = stream_socket_client('tcp://127.0.0.1:' . $port);
    fwrite($f, "POST /bodyleak HTTP/1.1\r\nHost: x\r\nContent-Length: 5\r\n\r\nhello");
    $fbuf = '';
    echo strtok(readUntil($f, "\r\n\r\n", $fbuf), "\r\n"), "\n";
    fclose($f);

    $e = stream_socket_client('tcp://127.0.0.1:' . $port);
    fwrite($e, "GET /http10 HTTP/1.0\r\nHost: x\r\n\r\n");
    $ebuf = '';
    echo strtok(readUntil($e, "\r\n\r\n", $ebuf), "\r\n"), "\n";
    fclose($e);

    $g = stream_socket_client('tcp://127.0.0.1:' . $port);
    fwrite($g, "GET /error HTTP/1.1\r\nHost: x\r\n\r\n");
    $gbuf = '';
    $ghead = readUntil($g, "\r\n\r\n", $gbuf);
    echo strtok($ghead, "\r\n"), "\n";
    echo readUntil($g, "\n", $gbuf);
    fclose($g);

    $h = stream_socket_client('tcp://127.0.0.1:' . $port);
    fwrite($h, "GET /lateStop HTTP/1.1\r\nHost: x\r\n\r\n");
    $hbuf = '';
    $hhead = readUntil($h, "\r\n\r\n", $hbuf);
    echo strtok($hhead, "\r\n"), "\n";

    $server->stop();
    echo readUntil($c, "\n", $buf);
    fclose($c);

    // Register AFTER stop() already ran: the hook fires inline.
    fwrite($h, "go\n");
    echo readUntil($h, "\n", $hbuf);
    fclose($h);

    $st = $server->stats();
    echo 'upgraded=', $st['upgraded'], ' errors=', $st['errors'], "\n";
});
