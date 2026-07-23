<?php

// Async HTTP/1.1 server on the spike runtime — TechEmpower-style plaintext + json.
// Accept loop spawns one fiber per connection; each fiber keep-alive-loops:
// read request, route, write response. All I/O suspends on the reactor.
//
//   ./http_bin              # listens on :8080
//   ab -k -n 100000 -c 256 http://127.0.0.1:8080/plaintext

use function Async\run;
use function Async\spawn;

run(function () {
    $errno = 0;
    $errstr = "";
    $server = stream_socket_server("tcp://0.0.0.0:8080", $errno, $errstr);
    if ($server === false) {
        echo "listen failed: ", $errstr, "\n";
        return;
    }
    stream_set_blocking($server, false);
    echo "http on :8080\n";
    while (true) {
        $conn = Async\accept($server);
        spawn(function () use ($conn) {
            serve($conn);
        });
    }
});

/** Keep-alive request loop for one connection. */
function serve(\Resource $conn): void
{
    while (true) {
        $req = Async\read($conn, 8192);
        if ($req === "") {
            break;                       // peer closed
        }
        // Route on a single byte: the request is "GET /json…" or "GET /plaintext…",
        // so the char after "GET /" (index 5) decides — no full-request strpos scan.
        if (\strlen($req) > 5 && $req[5] === "j") {
            $body = '{"message":"Hello, World!"}';
            $ctype = "application/json";
        } else {
            $body = "Hello, World!";
            $ctype = "text/plain";
        }
        $resp = "HTTP/1.1 200 OK\r\n"
              . "Server: manticore\r\n"
              . "Date: " . \gmdate("D, d M Y H:i:s") . " GMT\r\n"
              . "Content-Type: " . $ctype . "\r\n"
              . "Content-Length: " . (string)\strlen($body) . "\r\n"
              . "\r\n"
              . $body;
        Async\write($conn, $resp);
    }
    Async\close($conn);
}
