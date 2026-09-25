<?php

// Autobahn|Testsuite client driver: our WebSocket client against a running
// fuzzingserver. Fetches the case count, echoes every message back for each
// case (the server checks our answers), then asks the server to write its
// report. One case's failure must not stop the run.
//
//   bin/manticore compile tools/autobahn_client.php -o autobahn_client
//   ./autobahn_client 127.0.0.1

use Http\WebSocket as WS;

$host = $argv[1] ?? '127.0.0.1';

$cc = WS\connect("ws://$host:9001/getCaseCount");
$count = (int)$cc->receive()->data;
$cc->close();
fwrite(STDERR, "cases: $count\n");

for ($i = 1; $i <= $count; $i++) {
    try {
        $c = WS\connect(
            "ws://$host:9001/runCase?case=$i&agent=manticore",
            (new WS\Options())->compression(true)->maxMessageSize(64 << 20)
        );
        try {
            while (($m = $c->receive()) !== null) {
                $m->binary ? $c->sendBinary($m->data) : $c->send($m->data);
            }
        } finally {
            if ($c->isOpen()) {
                $c->close();
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "case $i: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    }
}

$done = WS\connect("ws://$host:9001/updateReports?agent=manticore");
$done->close();
fwrite(STDERR, "reports updated\n");
