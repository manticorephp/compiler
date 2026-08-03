<?php

// MANTICORE-ONLY (no Http\Server in php). The point of the whole layer: the
// ORDINARY php builtins work inside a handler — header(), header_remove(),
// headers_list(), http_response_code(), setcookie(), headers_sent() and a bare
// `echo` — and what they did is folded into the Response the handler returned.
// The expected output is written BY HAND.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 49620; $p < 49700; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) {
        $listener = $s;
        $port = $p;
        break;
    }
}
if ($listener === false) {
    echo "no free port\n";
    return;
}
stream_set_blocking($listener, false);

final class Client
{
    private string $buf = '';

    public function __construct(private \Resource $c) {}

    public function send(string $raw): void { fwrite($this->c, $raw); }

    public function readOne(): string
    {
        while (true) {
            $end = strpos($this->buf, "\r\n\r\n");
            if ($end !== false) {
                $len = 0;
                foreach (explode("\r\n", substr($this->buf, 0, $end)) as $line) {
                    if (stripos($line, 'content-length:') === 0) {
                        $len = (int)trim(substr($line, 15));
                    }
                }
                $whole = $end + 4 + $len;
                if (strlen($this->buf) >= $whole) {
                    $out = substr($this->buf, 0, $whole);
                    $this->buf = substr($this->buf, $whole);
                    return $out;
                }
            }
            $chunk = fread($this->c, 4096);
            if ($chunk === '') {
                $out = $this->buf;
                $this->buf = '';
                return $out;
            }
            $this->buf .= $chunk;
        }
    }

    public function close(): void { fclose($this->c); }
}

/** Status line, then every header except Date, then the body. */
function show(string $label, string $raw): void
{
    $end = strpos($raw, "\r\n\r\n");
    $head = $end === false ? $raw : substr($raw, 0, $end);
    $body = $end === false ? '' : substr($raw, $end + 4);
    $lines = explode("\r\n", $head);
    echo $label, ': ', $lines[0], "\n";
    foreach ($lines as $i => $line) {
        if ($i === 0 || stripos($line, 'date:') === 0) {
            continue;
        }
        echo '  ', $line, "\n";
    }
    echo '  body=', $body, "\n";
}

$server = \Http\Server::onListener($listener)->serverName('')->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            if ($req->path === '/ambient') {
                // Nothing but php builtins, and a bare Response to carry them.
                header('X-Ambient: yes');
                header('X-Two: 2');
                setcookie('sid', 'abc def', 0, '/', '', false, true);
                http_response_code(201);
                echo 'echoed body';
                return new \Http\Response();
            }
            if ($req->path === '/conflict') {
                // The explicit API wins over the ambient one — except
                // Set-Cookie, which accumulates.
                header('X-Who: ambient');
                header('X-Only-Ambient: 1');
                setcookie('a', '1');
                http_response_code(500);
                return (new \Http\Response())
                    ->header('X-Who', 'explicit')
                    ->cookie('b', '2', 0, '/', '', false, true, '')
                    ->status(202)
                    ->text('explicit body');
            }
            if ($req->path === '/both-bodies') {
                // A handler that echoes AND returns a body is a bug: the
                // explicit body wins and the echo is dropped.
                echo 'ECHOED';
                return (new \Http\Response())->text('returned');
            }
            if ($req->path === '/remove') {
                header('X-Gone: 1');
                header('X-Kept: 1');
                header_remove('X-Gone');
                $list = headers_list();
                return (new \Http\Response())->text('list=' . implode('|', $list));
            }
            if ($req->path === '/sent') {
                $before = headers_sent() ? 'y' : 'n';
                return (new \Http\Response())->text('sent-before=' . $before)
                    ->header('X-Sent-Before', $before);
            }
            if ($req->path === '/sent-stream') {
                return (new \Http\Response())->stream(function (\Http\ChunkedWriter $w): void {
                    // The head is on the wire by now, so this must say yes —
                    // and header() must no longer record.
                    $w->write('inside=' . (headers_sent() ? 'y' : 'n'));
                });
            }
            if ($req->path === '/isolated') {
                // A second request on the same flow must NOT see the first
                // one's headers.
                $n = count(headers_list());
                header('X-Round: 1');
                return (new \Http\Response())->text('carried=' . $n);
            }
            return (new \Http\Response(404))->text('nope');
        });
    });

    \Async\delay(0.05);
    $c = new Client(fsockopen('127.0.0.1', $port));

    $c->send("GET /ambient HTTP/1.1\r\nHost: t\r\n\r\n");
    show('ambient', $c->readOne());

    $c->send("GET /conflict HTTP/1.1\r\nHost: t\r\n\r\n");
    show('conflict', $c->readOne());

    $c->send("GET /both-bodies HTTP/1.1\r\nHost: t\r\n\r\n");
    show('both', $c->readOne());

    $c->send("GET /remove HTTP/1.1\r\nHost: t\r\n\r\n");
    show('remove', $c->readOne());

    $c->send("GET /sent HTTP/1.1\r\nHost: t\r\n\r\n");
    show('sent', $c->readOne());

    // Twice on the same connection: the second must start with an empty block.
    $c->send("GET /isolated HTTP/1.1\r\nHost: t\r\n\r\n");
    show('iso1', $c->readOne());
    $c->send("GET /isolated HTTP/1.1\r\nHost: t\r\n\r\n");
    show('iso2', $c->readOne());
    $c->close();

    $c2 = fsockopen('127.0.0.1', $port);
    fwrite($c2, "GET /sent-stream HTTP/1.1\r\nHost: t\r\n\r\n");
    $raw = '';
    while (true) {
        $part = fread($c2, 4096);
        if ($part === '') {
            break;
        }
        $raw .= $part;
        if (strpos($raw, "0\r\n\r\n") !== false) {
            break;
        }
    }
    $e = strpos($raw, "\r\n\r\n");
    echo 'stream: ', explode("\r\n", substr($raw, 0, $e))[0],
        ' chunk=', str_replace("\r\n", '.', substr($raw, $e + 4)), "\n";
    fclose($c2);

    $server->stop();
    echo 'served=', $server->stats()['served'], "\n";
});

echo "done\n";
