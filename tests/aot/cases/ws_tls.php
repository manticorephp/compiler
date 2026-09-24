<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// A WebSocket handshake and echo over the tls:// listener/client pair: the
// upgrade runs on top of a TLS stream (KIND_TLS), not a plain socket, and the
// 200 000-byte binary frame forces more than one TLS record each way.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

$cert = __DIR__ . '/../fixtures/tls_localhost.pem';
$ctx = stream_context_create(['ssl' => ['local_cert' => $cert, 'verify_peer' => false]]);

$errno = 0;
$errstr = '';
$l = stream_socket_server('tls://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
$server = \Http\Server::onListener($l, true)->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            return WS\upgrade($req, function (WS\Connection $ws): void {
                while (($m = $ws->receive()) !== null) {
                    $m->binary ? $ws->sendBinary($m->data) : $ws->send('echo:' . $m->data);
                }
            });
        });
    });

    $c = WS\connect('wss://127.0.0.1:' . $port . '/', (new WS\Options())->sslContext(['verify_peer' => false, 'verify_peer_name' => false]));
    $c->send('hi');
    echo $c->receive()->data, "\n";

    $c->sendBinary(str_repeat('b', 200000));
    $m = $c->receive();
    echo strlen($m->data), "\n";

    $c->close(1000, 'done');
    echo 'code=', $c->closeCode(), "\n";

    $server->stop();
});
