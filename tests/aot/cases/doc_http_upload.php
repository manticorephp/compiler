<?php
// Receiving a multipart/form-data upload.

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
$dest = sys_get_temp_dir() . '/mc_doc_upload_' . getmypid() . '.txt';

async(function () use ($server, $port, $dest) {
    spawn(fn () => $server->serve(function (Request $req) use ($dest): Response {
        $lines = ['title: ' . ($req->postArray()['title'] ?? '-')];
        foreach ($req->files() as $field => $f) {
            $lines[] = "$field: name={$f->name} type={$f->type} size={$f->size} error={$f->error} valid="
                . var_export($f->isValid(), true);
            $lines[] = 'contents: ' . $f->contents();
            $lines[] = 'moved: ' . var_export($f->moveTo($dest), true);
            $lines[] = 'valid after move: ' . var_export($f->isValid(), true);
        }
        return (new Response())->text(implode("\n", $lines) . "\n");
    }));

    $b = 'XBOUNDARYX';
    $body = "--$b\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nQuarterly report\r\n"
        . "--$b\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"q3.txt\"\r\nContent-Type: text/plain\r\n\r\nrevenue up\r\n"
        . "--$b--\r\n";
    $raw = fetch(
        $port,
        "POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary=$b\r\nContent-Length: " . strlen($body) . "\r\n",
        $body,
    );
    echo explode("\r\n\r\n", $raw, 2)[1];
    echo 'on disk: ', file_get_contents($dest), "\n";
    unlink($dest);
    $server->stop();
});
