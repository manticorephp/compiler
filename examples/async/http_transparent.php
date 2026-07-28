<?php

// TRANSPARENT async HTTP/1.1 server: written with ORDINARY blocking-style PHP
// stream I/O — stream_socket_accept / fread / fwrite / fclose — no Async\read or
// Async\write anywhere. Because it runs inside Async\async(), the stdlib routes each
// would-block through the netpoller (\Runtime\AsyncHook) and suspends the fiber.
// This is the north-star: normal PHP I/O code that "just works" concurrently.
//
//   ./http_transparent_bin        # :8080, prefork
//   wrk -t4 -c64 -d10s http://127.0.0.1:8080/plaintext

use function Async\async;
use function Async\spawn;

const WORKERS = 8;

$errno = 0;
$errstr = "";
$server = stream_socket_server("tcp://0.0.0.0:8080", $errno, $errstr);
if ($server === false) {
    echo "listen failed: ", $errstr, "\n";
} else {
    stream_set_blocking($server, false);
    $worker = \Process\workers(WORKERS);
    if ($worker === 0) {
        echo "transparent http on :8080 (", WORKERS, " workers)\n";
    }
    async(function () use ($server) {
        while (true) {
            $conn = stream_socket_accept($server);      // plain accept — auto-suspends
            if ($conn === false) {
                continue;
            }
            spawn(function () use ($conn) {
                serve($conn);
            });
        }
    });
}

// Both endpoints have a fully-known Content-Length: the response is entirely
// static, and the fastest thing to send is one precomputed string per route. No
// per-request concat, no strlen/itoa on Content-Length, one send() syscall.
const PLAINTEXT_RESP = "HTTP/1.1 200 OK\r\nServer: manticore\r\n"
    . "Content-Type: text/plain\r\nContent-Length: 13\r\n\r\nHello, World!";
const JSON_RESP = "HTTP/1.1 200 OK\r\nServer: manticore\r\n"
    . "Content-Type: application/json\r\nContent-Length: 27\r\n\r\n"
    . '{"message":"Hello, World!"}';

// A precomputed header PREFIX shared by both routes ("HTTP/1.1 200 OK\r\nServer:
// manticore\r\n"). Used with the vectored form of fwrite() below to demonstrate
// writev(2): the response goes out in a single syscall as [shared prefix, per-
// route tail]. For a fully-static response this is equivalent to sending the
// concatenated string; the shape stays useful whenever the body is dynamic.
const RESP_PREFIX = "HTTP/1.1 200 OK\r\nServer: manticore\r\n";
const PLAINTEXT_TAIL = "Content-Type: text/plain\r\nContent-Length: 13\r\n\r\nHello, World!";
const JSON_TAIL = "Content-Type: application/json\r\nContent-Length: 27\r\n\r\n"
    . '{"message":"Hello, World!"}';

/** Keep-alive loop written with plain stream I/O; every call auto-suspends. */
function serve(\Resource $conn): void
{
    while (true) {
        $req = fread($conn, 8192);                       // plain fread — auto-suspends
        if ($req === "") {
            break;
        }
        // fwrite($conn, [prefix, tail]) → writev(2): one syscall, no userspace
        // concat. See src/Runtime/Stdlib/Io.php `__mc_stream_sendv`.
        if (\strlen($req) > 5 && $req[5] === "j") {
            fwrite($conn, [RESP_PREFIX, JSON_TAIL]);
        } else {
            fwrite($conn, [RESP_PREFIX, PLAINTEXT_TAIL]);
        }
    }
    fclose($conn);                                       // plain fclose — drops the watcher
}
