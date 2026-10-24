<?php
// JSON in, JSON out: there is no Response::json(), json_encode() and json_decode() do the work.

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
        if ($req->contentType() !== 'application/json') {
            return (new Response(415))->text("send application/json\n");
        }
        $data = json_decode($req->body(), true);
        if (!is_array($data) || !isset($data['name'])) {
            return (new Response(400))->type('application/json')->body(json_encode(['error' => 'name required']));
        }
        return (new Response(201))
            ->type('application/json')
            ->body(json_encode(['greeting' => 'hello ' . $data['name'], 'method' => $req->method]));
    }));

    $send = function (string $type, string $json) use ($port): void {
        $raw = fetch(
            $port,
            "POST /users HTTP/1.1\r\nHost: localhost\r\nContent-Type: $type\r\nContent-Length: " . strlen($json) . "\r\n",
            $json,
        );
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        echo strtok($head, "\r\n"), ' ', trim($body), "\n";
    };
    $send('application/json', '{"name":"Ada"}');
    $send('application/json', '{"nope":1}');
    $send('text/plain', 'Ada');
    $server->stop();
});
