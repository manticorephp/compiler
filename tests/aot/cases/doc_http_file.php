<?php
// Serving a file: Content-Type, ETag, conditional requests and byte ranges.

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

$dir = sys_get_temp_dir() . '/mc_doc_file_' . getmypid();
mkdir($dir);
file_put_contents($dir . '/notes.txt', '0123456789');

$server = Server::onListener($listener)->acceptWait(0.02);

async(function () use ($server, $port, $dir) {
    spawn(fn () => $server->serve(function (Request $req) use ($dir): Response {
        $file = Http\safePath($dir, $req->path);
        return $file === null ? (new Response(404))->text("no such file\n") : (new Response())->file($file);
    }));

    $get = function (string $extra = '') use ($port): array {
        $raw = fetch($port, "GET /notes.txt HTTP/1.1\r\nHost: localhost\r\n$extra");
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $headers = [];
        foreach (explode("\r\n", $head) as $i => $line) {
            if ($i > 0) {
                [$k, $v] = explode(': ', $line, 2);
                $headers[strtolower($k)] = $v;
            }
        }
        return [strtok($head, "\r\n"), $headers, $body];
    };

    [$status, $h, $body] = $get();
    echo "$status | {$h['content-type']} | {$h['accept-ranges']} | $body\n";

    [$status, , $body] = $get('If-None-Match: ' . $h['etag'] . "\r\n");
    echo "$status | body: [", $body, "]\n";

    [$status, $h2, $body] = $get("Range: bytes=2-5\r\n");
    echo "$status | {$h2['content-range']} | $body\n";

    [$status, $h2] = $get("Range: bytes=50-60\r\n");
    echo "$status | {$h2['content-range']}\n";

    $raw = fetch($port, "GET /../etc/passwd HTTP/1.1\r\nHost: localhost\r\n");
    echo strtok($raw, "\r\n"), "\n";
    $server->stop();
    unlink($dir . '/notes.txt');
    rmdir($dir);
});
