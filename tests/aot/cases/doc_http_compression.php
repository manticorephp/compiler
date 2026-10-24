<?php
// Opt-in gzip for buffered, text-like responses.

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

$server = Server::onListener($listener)->acceptWait(0.02)->compression(true, minBytes: 100);

async(function () use ($server, $port) {
    spawn(fn () => $server->serve(function (Request $req): Response {
        return match ($req->path) {
            '/big' => (new Response())->type('text/plain')->body(str_repeat('compress me ', 100)),
            '/small' => (new Response())->type('text/plain')->body('tiny'),
            default => (new Response())->type('image/png')->body(str_repeat('x', 500)),
        };
    }));

    foreach ([['/big', 'gzip'], ['/big', 'identity'], ['/small', 'gzip'], ['/image', 'gzip']] as [$path, $accept]) {
        $raw = fetch($port, "GET $path HTTP/1.1\r\nHost: localhost\r\nAccept-Encoding: $accept\r\n");
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $h = [];
        foreach (explode("\r\n", $head) as $line) {
            if (str_contains($line, ': ')) {
                [$k, $v] = explode(': ', $line, 2);
                $h[strtolower($k)] = $v;
            }
        }
        $decoded = ($h['content-encoding'] ?? '') === 'gzip' ? gzdecode($body) : $body;
        echo str_pad("$path ($accept)", 18),
            'encoding=', $h['content-encoding'] ?? '-',
            ' vary=', $h['vary'] ?? '-',
            ' wire<decoded=', var_export(strlen($body) < strlen($decoded), true),
            ' decoded=', strlen($decoded), "\n";
    }
    $server->stop();
});
