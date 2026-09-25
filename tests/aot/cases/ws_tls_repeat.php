<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// The original ws_tls.php 200 KB round trip (see task-8), repeated 10x over one
// connection: each iteration re-opens the flaky window that Task 8's crash
// exposed (a TLS record only partly on the wire, under the netpoller) and that
// round-1's __mc_stream_recv_into fix closed. One clean run does not rule out
// a residual race at low probability; ten in a row is a stronger pin.

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

    for ($i = 0; $i < 10; $i = $i + 1) {
        $c->send('hi');
        $r1 = $c->receive()->data;
        $c->sendBinary(str_repeat('b', 200000));
        $r2 = strlen($c->receive()->data);
        echo $i, ' ', $r1, ' ', $r2, "\n";
    }

    $c->close(1000, 'done');
    echo 'code=', $c->closeCode(), "\n";

    $server->stop();
});
