<?php

// Connect to a WebSocket server, send one message, print every reply until
// the server closes. No Async\async() scope needed — outside one this runs
// on an ordinary blocking socket.
//
//   bin/manticore compile examples/http/ws_client.php -o ws_client
//   ./ws_client ws://127.0.0.1:9001/ 'hello'

use Http\WebSocket as WS;

if ($argc < 3) {
    \fwrite(\STDERR, "usage: ws_client <url> <message>\n");
    exit(1);
}

$ws = WS\connect($argv[1]);
$ws->send($argv[2]);
foreach ($ws as $m) {
    echo $m->data, "\n";
}
echo 'closed: ', $ws->closeCode(), $ws->closeReason() !== '' ? ' ' . $ws->closeReason() : '', "\n";
