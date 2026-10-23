<?php
// Behind a reverse proxy: forwarded headers are believed only from trusted peers.

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

function run(int $port, Server $server, string $label): void
{
    $raw = fetch($port, "GET / HTTP/1.1\r\nHost: internal\r\nX-Forwarded-For: 203.0.113.7, 10.1.2.3\r\nX-Forwarded-Proto: https\r\nX-Forwarded-Host: shop.example\r\n");
    echo $label, ': ', trim(explode("\r\n\r\n", $raw, 2)[1]), "\n";
}

$server = Server::onListener($listener)->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(fn () => $server->serve(function (Request $req): Response {
        // The peer is always the socket; remoteAddr may come from a trusted proxy.
        return (new Response())->text(
            'remote=' . (str_starts_with($req->remoteAddr, '127.0.0.1:') ? 'peer' : $req->remoteAddr)
            . ' secure=' . var_export($req->secure, true)
            . ' host=' . $req->header('Host')
            . ' peer-is-loopback=' . (str_starts_with($req->peerAddr, '127.0.0.1:') ? 'yes' : 'no')
        );
    }));

    run($port, $server, 'untrusted     ');

    // Settings are read when a connection is accepted, so this applies to the next request.
    // X-Forwarded-For is walked right to left; the first hop that is not trusted is the client.
    $server->trustedProxies(['127.0.0.1', '10.0.0.0/8'], Http\Proxy::ALL);
    run($port, $server, 'trusted       ');

    // Grant only FOR: Proto and Host are ignored, and 10.1.2.3 is not in the list, so it is the client.
    $server->trustedProxies(['127.0.0.1'], Http\Proxy::FOR);
    run($port, $server, 'FOR only      ');
    $server->stop();
});
