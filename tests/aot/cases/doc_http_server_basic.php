<?php
// A handler is callable(Request): Response. Server and client share one process.

use Http\{Request, Response, Server};
use function Async\{async, spawn};

// Bind a loopback listener on a free port (no fixed port in the example).
$listener = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($listener, false);
$name = stream_socket_get_name($listener, false);
$port = (int) substr($name, strrpos($name, ':') + 1);

$server = Server::onListener($listener)->acceptWait(0.02);

/** One request on a fresh connection; returns the raw response. */
function fetch(int $port, string $request): string
{
    $c = fsockopen('127.0.0.1', $port);
    fwrite($c, $request . "Connection: close\r\n\r\n");
    $raw = '';
    while (($chunk = fread($c, 8192)) !== '' && $chunk !== false) {
        $raw .= $chunk;
    }
    fclose($c);
    return $raw;
}

async(function () use ($server, $port) {
    spawn(fn () => $server->serve(function (Request $req): Response {
        if ($req->path === '/hello') {
            return (new Response())->text('hello ' . $req->query('name', 'world') . "\n");
        }
        return (new Response(404))->text("not found\n");
    }));

    foreach (['/hello', '/hello?name=Ada', '/missing'] as $target) {
        $raw = fetch($port, "GET $target HTTP/1.1\r\nHost: localhost\r\n");
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        echo strtok($head, "\r\n"), ' | ', trim($body), "\n";
    }

    $server->stop();
    $stats = $server->stats();
    echo "served: {$stats['served']}\n";
});
