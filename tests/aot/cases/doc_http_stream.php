<?php
// Streaming a response body in chunks with Response::stream().

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

$server = Server::onListener($listener)->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(fn () => $server->serve(function (Request $req): Response {
        return (new Response())->type('text/plain')->stream(function (Http\ChunkedWriter $w) {
            foreach (['alpha', 'beta', 'gamma'] as $row) {
                $w->write($row . "\n");
                $w->flush(); // without flush() the writer batches up to 64 KiB
            }
        });
    }));

    $raw = fetch($port, "GET /rows HTTP/1.1\r\nHost: localhost\r\n");
    [$head, $body] = explode("\r\n\r\n", $raw, 2);
    foreach (explode("\r\n", $head) as $line) {
        if (!str_starts_with($line, 'Date:') && !str_starts_with($line, 'Server:')) {
            echo $line, "\n";
        }
    }
    echo "-- wire body\n", str_replace("\r\n", '\r\n' . "\n", $body);
    echo "-- HTTP/1.0 client: no chunking, the close ends the body\n";
    $raw = fetch($port, "GET /rows HTTP/1.0\r\n");
    [$head, $body] = explode("\r\n\r\n", $raw, 2);
    echo strtok($head, "\r\n"), "\n", $body;
    $server->stop();
});
