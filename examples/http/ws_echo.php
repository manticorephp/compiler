<?php

// Autobahn|Testsuite echo server: upgrade every request to a WebSocket
// connection and echo each message back verbatim, text as text and binary
// as binary. Port from argv[1] (default 9001) so tools/autobahn.sh can pick
// a fresh one per run.
//
//   bin/manticore compile examples/http/ws_echo.php -o ws_echo && ./ws_echo [port]

use Http\Request;
use Http\Response;
use Http\Server;
use Http\WebSocket as WS;

$port = $argv[1] ?? '9001';

(new Server('tcp://0.0.0.0:' . $port))
    ->serve(function (Request $req): Response {
        return WS\upgrade($req, function (WS\Connection $ws): void {
            foreach ($ws as $m) {
                $m->binary ? $ws->sendBinary($m->data) : $ws->send($m->data);
            }
        }, (new WS\Options())->compression(true)->maxMessageSize(64 << 20));
    });
