<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// Our client against our server in one process: subprotocol negotiation, the
// request target reaching the server, echo of every length form, UTF-8 text,
// ping, the close handshake. Then a Connection handed to a reader task: the
// connecting task's close() between two of that reader's receive() calls must
// not take the read — the reader's next receive() sees the peer's Close.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

final class Seen { public static string $path = ''; }

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
$server = \Http\Server::onListener($l)->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            if ($req->path === '/chat') {
                Seen::$path = $req->target;
            }
            $slow = $req->path === '/slow';
            return WS\upgrade($req, function (WS\Connection $ws) use ($slow): void {
                while (($m = $ws->receive()) !== null) {
                    $m->binary ? $ws->sendBinary($m->data) : $ws->send('echo:' . $m->data);
                    if ($slow) {
                        \Async\delay(0.3);
                    }
                }
            }, (new WS\Options())->protocols(['v2', 'v1']));
        });
    });

    $c = WS\connect('ws://127.0.0.1:' . $port . '/chat?room=1', (new WS\Options())->protocols(['v1']));
    echo 'protocol=', $c->protocol(), "\n";
    $c->send('hi');
    echo $c->receive()->data, "\n";
    foreach ([0, 125, 126, 65535, 65536, 1048576] as $n) {
        $c->sendBinary(str_repeat('b', $n));
        $m = $c->receive();
        echo $n, ' ', $m->binary ? 'bin' : 'text', ' ', strlen($m->data), "\n";
    }
    $c->send("h\u{e9}llo \u{1f600}");
    echo $c->receive()->data, "\n";
    $c->ping('p');
    $c->close(1000, 'done');
    echo 'client code=', $c->closeCode(), ' open=', $c->isOpen() ? 'yes' : 'no', "\n";
    try {
        $c->send('late');
    } catch (WS\ConnectionClosedException $e) {
        echo "closed exception\n";
    }
    echo 'server path=', Seen::$path, "\n";

    $d = WS\connect('ws://127.0.0.1:' . $port . '/slow');
    $reader = spawn(function () use ($d): string {
        $got = '';
        try {
            while (($m = $d->receive()) !== null) {
                $got .= $m->data . ' ';
                \Async\delay(0.15);
            }
            return $got . 'null code=' . $d->closeCode();
        } catch (\Throwable $e) {
            return $got . get_class($e);
        }
    });
    $d->send('x');
    \Async\delay(0.05);
    $t0 = microtime(true);
    $d->close(1000, 'bye');
    echo 'closer: ', microtime(true) - $t0 < 0.1 ? 'returned at once' : 'blocked', "\n";
    echo 'reader: ', $reader->await(), "\n";
    $server->stop();
});
