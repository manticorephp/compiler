<?php

// Async HTTP/1.1 server on the spike runtime — TechEmpower-style plaintext + json.
// Accept loop spawns one fiber per connection; each fiber keep-alive-loops:
// read request, route, write response. All I/O suspends on the reactor.
//
//   ./http_bin              # listens on :8080
//   ab -k -n 100000 -c 256 http://127.0.0.1:8080/plaintext

use function Async\async;
use function Async\spawn;

const WORKERS = 8;

// PREFORK: bind ONE listener, then fork — every worker shares that fd and pulls
// connections off it with accept(). Portable distribution (macOS SO_REUSEPORT does
// NOT load-balance across sockets; a shared accept queue does). SO_REUSEADDR still
// helps restarts.
$errno = 0;
$errstr = "";
$server = stream_socket_server("tcp://0.0.0.0:8080", $errno, $errstr);
if ($server === false) {
    echo "listen failed: ", $errstr, "\n";
} else {
    stream_set_blocking($server, false);
    $worker = \Process\workers(WORKERS);    // fork; children inherit the listener fd
    if ($worker === 0) {
        echo "http on :8080 (", WORKERS, " workers, prefork)\n";
    }
    async(function () use ($server) {
        // A ceiling on connections in flight, per worker. It is deliberately far
        // above anything a benchmark reaches — at 256 concurrent connections this
        // never binds, and the number below is unchanged by it — but an accept loop
        // with no bound answers a flood by spawning until the process dies, and
        // this file is the one people copy. Past the ceiling the loop stops
        // accepting and the kernel's backlog holds the queue, which is what
        // backpressure is: a queue somewhere that is not the heap.
        $inflight = new Async\Semaphore(4096);
        while (true) {
            $conn = Async\accept($server);
            $inflight->acquire();
            spawn(function () use ($conn, $inflight) {
                try {
                    serve($conn);
                } finally {
                    $inflight->release();
                }
            });
        }
    });
}

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
