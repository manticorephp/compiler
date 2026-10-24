<?php
// Building responses: status, headers, cookies, redirects, content types.

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
        switch ($req->path) {
            case '/created':
                return (new Response())
                    ->status(201)
                    ->header('X-Request-Id', 'r-1')
                    ->type('application/json')
                    ->body(json_encode(['id' => 7]));
            case '/old':
                return (new Response())->redirect('/new', 301);
            case '/login':
                return (new Response())
                    ->text("welcome\n")
                    ->cookie('sid', 'a b', path: '/app', sameSite: 'Strict')
                    ->addHeader('Set-Cookie', 'theme=dark; Path=/')
                    ->header('X-Tmp', 'gone')
                    ->withoutHeader('X-Tmp');
            case '/page':
                return (new Response())->html('<h1>Hi</h1>')->write('<p>appended</p>');
            default:
                return new Response(204);
        }
    }));

    foreach (['/created', '/old', '/login', '/page', '/none'] as $path) {
        $raw = fetch($port, "GET $path HTTP/1.1\r\nHost: localhost\r\n");
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        echo "== $path\n";
        foreach (explode("\r\n", $head) as $line) {
            if (!str_starts_with($line, 'Date:') && !str_starts_with($line, 'Server:')) {
                echo $line, "\n";
            }
        }
        echo "body: $body\n";
    }
    $server->stop();
});
