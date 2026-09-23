<?php

// MANTICORE-ONLY (no Http\Server in php); expected output written by hand.
// A 101 response hands the socket AND the bytes already read past the head to
// a takeover closure, in the connection's own fiber; the connection is never
// reused for HTTP after it. A stop hook reaches a live takeover. A takeover
// that is not a 101 is a handler bug: 500.

use function Async\async;
use function Async\spawn;

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);

$server = \Http\Server::onListener($l)->acceptWait(0.02)->serverName('');

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
            return (new \Http\Response(101))
                ->header('Upgrade', 'x-shout')
                ->header('Connection', 'Upgrade')
                ->takeover(function (\Resource $c, \Buffer\ByteBuffer $b, \Http\Server $s): void {
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

    $d = stream_socket_client('tcp://127.0.0.1:' . $port);
    fwrite($d, "GET /bad HTTP/1.1\r\nHost: x\r\n\r\n");
    $dbuf = '';
    echo strtok(readUntil($d, "\r\n\r\n", $dbuf), "\r\n"), "\n";
    fclose($d);

    $server->stop();
    echo readUntil($c, "\n", $buf);
    fclose($c);
    $st = $server->stats();
    echo 'upgraded=', $st['upgraded'], ' errors=', $st['errors'], "\n";
});
