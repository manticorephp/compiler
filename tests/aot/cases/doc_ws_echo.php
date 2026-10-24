<?php
// An echo server and a client in one program, over loopback.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
$server = \Http\Server::onListener($l)->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            return WS\upgrade($req, function (WS\Connection $ws): void {
                foreach ($ws as $m) {
                    if ($m->binary) {
                        $ws->sendBinary($m->data);
                    } else {
                        $ws->send('echo: ' . $m->data);
                    }
                }
            });
        });
    });

    $ws = WS\connect("ws://127.0.0.1:$port/");
    $ws->send('hello');
    $m = $ws->receive();
    echo $m->data, ' (', $m->binary ? 'binary' : 'text', ")\n";

    $ws->sendBinary("\x00\x01\x02");
    $m = $ws->receive();
    echo bin2hex($m->data), ' (', $m->binary ? 'binary' : 'text', ")\n";

    $ws->close(1000, 'done');
    echo 'open: ', var_export($ws->isOpen(), true), ', close code: ', $ws->closeCode(), "\n";
    $server->stop();
});
