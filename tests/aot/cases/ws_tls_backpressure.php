<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// Pin for round-1 review item #2: async fwrite() over TLS used to park on
// WRITABLE only (wrong direction for an SSL_write WANT_READ) and retry the
// blocked send exactly ONCE — a second WANT_WRITE/WOULD-BLOCK right after the
// park reported a short write instead of continuing.
//
// A 4 MiB binary frame each way over wss://, receiver deliberately not
// draining for a beat first: the loopback TCP receive buffer (far smaller than
// 4 MiB) backs up, so the sender's fwrite must survive many WANT_WRITE parks
// in a row, not just one, to land the whole frame. SSL_write (no
// SSL_MODE_ENABLE_PARTIAL_WRITE) is all-or-nothing per call and must be
// retried with the SAME buffer — __mc_stream_send_retry does; the old
// retry-once code did not.

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
                    if (!$m->binary) {
                        // 'go': do NOT loop back to receive() right away — let the
                        // client's binary frame back up against the kernel socket
                        // buffer before this side drains any of it.
                        \Async\delay(0.2);
                        continue;
                    }
                    $ws->sendBinary($m->data);
                }
            });
        });
    });

    $c = WS\connect('wss://127.0.0.1:' . $port . '/', (new WS\Options())->sslContext(['verify_peer' => false, 'verify_peer_name' => false]));

    $payload = str_repeat('x', 4 * 1024 * 1024);
    $c->send('go');
    $c->sendBinary($payload);

    // Symmetric on the way back: let the echoed 4 MiB back up before this side
    // (the client) starts draining it, so the SERVER's write is the one under
    // backpressure this time.
    \Async\delay(0.2);
    $m = $c->receive();
    echo strlen($m->data), ' ', $m->data === $payload ? 'match' : 'mismatch', "\n";

    $c->close(1000, 'done');
    echo 'code=', $c->closeCode(), "\n";

    $server->stop();
});
