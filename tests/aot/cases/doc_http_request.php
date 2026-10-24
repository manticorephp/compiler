<?php
// Everything a handler can read from the request.

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
        $out = [
            'method' => $req->method,
            'path' => $req->path,
            'target' => $req->target,
            'query string' => $req->queryString,
            'version' => $req->version,
            'name' => $req->query('name', '-'),
            'page' => $req->query('page', '1'),
            'queryArray' => json_encode($req->queryArray()),
            'user-agent' => $req->header('User-Agent', '-'),
            'content type' => $req->contentType(),
            'content length' => $req->contentLength(),
            'cookie sid' => $req->cookie('sid', '-'),
            'post title' => $req->postArray()['title'] ?? '-',
            'body' => $req->body(),
            'keep-alive' => var_export($req->isKeepAlive(), true),
            'is POST' => var_export($req->is(Http\Method::Post), true),
            'secure' => var_export($req->secure, true),
        ];
        $text = '';
        foreach ($out as $k => $v) {
            $text .= "$k: $v\n";
        }
        return (new Response())->text($text);
    }));

    $form = 'title=Hello+World&x=1';
    $raw = fetch(
        $port,
        "POST /a%2Fb/../c?name=Ada&tags[]=x&tags[]=y HTTP/1.1\r\n"
        . "Host: localhost\r\nUser-Agent: demo/1.0\r\nCookie: sid=abc123; theme=dark\r\n"
        . "Content-Type: application/x-www-form-urlencoded; charset=utf-8\r\n"
        . 'Content-Length: ' . strlen($form) . "\r\n",
        $form,
    );
    [$head, $body] = explode("\r\n\r\n", $raw, 2);
    echo strtok($head, "\r\n"), "\n", $body;
    $server->stop();
});
