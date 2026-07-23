<?php

// Async echo round-trip: a server task accepts one connection and echoes; a
// client task connects, sends, and reads the reply — both driven by the reactor.
// Self-contained (no external client) so it runs as a deterministic test.

use function Async\run;
use function Async\spawn;

run(function () {
    $errno = 0;
    $errstr = "";
    $addr = "127.0.0.1:39217";
    $server = stream_socket_server("tcp://" . $addr, $errno, $errstr);
    if ($server === false) {
        echo "listen failed: ", $errstr, "\n";
        return;
    }
    echo "listening on ", $addr, "\n";

    // One connection per accept, echoed on its own fiber.
    spawn(function () use ($server) {
        $conn = Async\accept($server);
        $msg = Async\read($conn, 1024);
        Async\write($conn, "echo:" . $msg);
        fclose($conn);
    });

    // Client, on the root task.
    $client = Async\connect("tcp://" . $addr);
    Async\write($client, "hello");
    $reply = Async\read($client, 1024);
    echo "reply: ", $reply, "\n";
    fclose($client);
    fclose($server);
});
