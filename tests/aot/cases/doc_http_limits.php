<?php
// Limits and error handling: oversized heads and bodies, a throwing handler, onError().

use Http\{Request, Response, Server};
use function Async\{async, spawn};

// Bind a loopback listener on a free port (no fixed port in the example).
$listener = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($listener, false);
$name = stream_socket_get_name($listener, false);
$port = (int) substr($name, strrpos($name, ':') + 1);

/** One request on a fresh connection; returns the raw response. */
function fetch(int $port, string $request, string $body = ''): string
{
    $c = fsockopen('127.0.0.1', $port);
    fwrite($c, $request . "Connection: close\r\n\r\n" . $body);
    $raw = '';
    while (($chunk = fread($c, 8192)) !== '' && $chunk !== false) {
        $raw .= $chunk;
    }
    fclose($c);
    return $raw;
}

$server = Server::onListener($listener)
    ->acceptWait(0.02)
    ->maxHeaderBytes(1024)  // 431 when the head is larger
    ->maxBodySize(16)       // 413 when the declared body is larger
    ->onError(fn (Throwable $e, Request $r) => (new Response(500))->text('caught: ' . $e->getMessage() . "\n"));

async(function () use ($server, $port) {
    spawn(fn () => $server->serve(function (Request $req): Response {
        if ($req->path === '/boom') {
            throw new RuntimeException('handler failed');
        }
        return (new Response())->text('ok, body of ' . strlen($req->body()) . " bytes\n");
    }));

    $try = function (string $label, string $request, string $body = '') use ($port): void {
        $raw = fetch($port, $request, $body);
        [$head, $payload] = explode("\r\n\r\n", $raw, 2);
        echo str_pad($label, 16), strtok($head, "\r\n"), ' ', trim($payload), "\n";
    };
    $try('normal', "GET / HTTP/1.1\r\nHost: h\r\n");
    $try('big header', "GET / HTTP/1.1\r\nHost: h\r\nX-Pad: " . str_repeat('a', 2000) . "\r\n");
    $try('small body', "POST / HTTP/1.1\r\nHost: h\r\nContent-Length: 5\r\n", 'hello');
    $try('big body', "POST / HTTP/1.1\r\nHost: h\r\nContent-Length: 17\r\n", str_repeat('x', 17));
    $try('no length', "POST / HTTP/1.1\r\nHost: h\r\n");
    $try('no Host', "GET / HTTP/1.1\r\n");
    $try('HTTP/2.0', "GET / HTTP/2.0\r\nHost: h\r\n");
    $try('handler throws', "GET /boom HTTP/1.1\r\nHost: h\r\n");
    $server->stop();
});
