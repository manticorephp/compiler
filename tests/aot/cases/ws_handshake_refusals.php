<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// upgrade() answers a request that is not a valid WebSocket handshake with an
// ordinary response (400 / 426 + the supported version / 403 for a foreign
// Origin), picks the first subprotocol in the CLIENT's order that the server
// supports, and accepts header tokens case-insensitively.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
$server = \Http\Server::onListener($l)->acceptWait(0.02);

function req(int $port, string $head): void
{
    $c = stream_socket_client('tcp://127.0.0.1:' . $port);
    fwrite($c, $head . "\r\n");
    $buf = '';
    while (strpos($buf, "\r\n\r\n") === false) {
        $chunk = fread($c, 4096);
        if ($chunk === '' || $chunk === false) { break; }
        $buf .= $chunk;
    }
    fclose($c);
    $lines = explode("\r\n", substr($buf, 0, (int)strpos($buf, "\r\n\r\n")));
    $out = $lines[0];
    foreach ($lines as $line) {
        $lc = strtolower($line);
        if (str_starts_with($lc, 'sec-websocket-version:') || str_starts_with($lc, 'sec-websocket-protocol:')) {
            $out .= ' | ' . $lc;
        }
    }
    echo $out, "\n";
}

$up = "Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\n";
$key = "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n";

async(function () use ($server, $port, $up, $key) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            $o = new WS\Options();
            if ($req->path === '/o') {
                $o->allowedOrigins(['https://good.example']);
            } elseif ($req->path === '/p') {
                $o->protocols(['superchat', 'chat']);
            }
            return WS\upgrade($req, function (WS\Connection $ws): void {
                foreach ($ws as $m) {
                }
            }, $o);
        });
    });

    req($port, "POST /ws HTTP/1.1\r\nHost: t\r\nContent-Length: 0\r\n" . $up . $key);
    req($port, "GET /ws HTTP/1.1\r\nHost: t\r\n" . $up);
    req($port, "GET /ws HTTP/1.1\r\nHost: t\r\n" . $up . 'Sec-WebSocket-Key: ' . base64_encode(str_repeat('a', 15)) . "\r\n");
    req($port, "GET /ws HTTP/1.1\r\nHost: t\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 8\r\n" . $key);
    req($port, "GET /o HTTP/1.1\r\nHost: t\r\nOrigin: https://evil.example\r\n" . $up . $key);
    req($port, "GET /o HTTP/1.1\r\nHost: t\r\nOrigin: https://good.example\r\n" . $up . $key);
    req($port, "GET /p HTTP/1.1\r\nHost: t\r\nSec-WebSocket-Protocol: chat, superchat\r\n" . $up . $key);
    req($port, "GET /p HTTP/1.1\r\nHost: t\r\nSec-WebSocket-Protocol: mqtt\r\n" . $up . $key);
    req($port, "GET /ws HTTP/1.0\r\n" . $up . $key);
    req($port, "GET /ws HTTP/1.1\r\nHost: t\r\nUpgrade: WebSocket\r\nConnection: keep-alive, Upgrade\r\nSec-WebSocket-Version: 13\r\n" . $key);
    $server->stop();
});
