<?php
// php's header(), setcookie(), http_response_code() and echo work inside a handler.

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
        if ($req->path === '/legacy') {
            header('X-Powered-By: legacy-code');
            setcookie('visited', 'yes', 0, '/');
            http_response_code(202);
            echo "echoed body\n";        // becomes the body: the Response below has none
            return new Response();
        }
        // The explicit API wins: Response status and body replace the ambient ones.
        http_response_code(202);
        echo "dropped\n";
        return (new Response(201))->text("explicit body\n");
    }));

    foreach (['/legacy', '/explicit'] as $path) {
        $raw = fetch($port, "GET $path HTTP/1.1\r\nHost: localhost\r\n");
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        echo "== $path\n";
        foreach (explode("\r\n", $head) as $line) {
            if (!str_starts_with($line, 'Date:') && !str_starts_with($line, 'Server:')) {
                echo $line, "\n";
            }
        }
        echo $body;
    }
    $server->stop();
});
