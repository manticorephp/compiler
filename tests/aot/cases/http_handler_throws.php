<?php

// MANTICORE-ONLY (no Http\Server in php): a loopback server with NO onError()
// whose handler throws. Server::runHandler's own catch-all must answer the
// canned 500 (Connection: close) and the server must survive to serve the
// next connection. Both a \RuntimeException and a bare \Error are thrown;
// stats() counts each as an error. The expected output is written by hand.

use function Async\async;
use function Async\spawn;

final class Client
{
    private string $buf = '';

    public function __construct(private \Resource $c) {}

    public function send(string $raw): void { fwrite($this->c, $raw); }

    /** One complete response; body length taken from Content-Length. */
    public function readOne(): string
    {
        while (true) {
            $end = strpos($this->buf, "\r\n\r\n");
            if ($end !== false) {
                $head = substr($this->buf, 0, $end);
                $len = 0;
                foreach (explode("\r\n", $head) as $line) {
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

function show(string $label, string $raw): void
{
    $end = strpos($raw, "\r\n\r\n");
    $head = $end === false ? $raw : substr($raw, 0, $end);
    $body = $end === false ? '' : substr($raw, $end + 4);
    $lines = explode("\r\n", $head);
    $conn = '';
    foreach ($lines as $line) {
        if (stripos($line, 'connection:') === 0) {
            $conn = trim(substr($line, 11));
        }
    }
    echo $label, ': ', $lines[0], ' connection=', $conn, ' body=', $body, "\n";
}

function get(string $path): string
{
    return 'GET ' . $path . " HTTP/1.1\r\nHost: t\r\n\r\n";
}

function handler(\Http\Request $req): \Http\Response
{
    if ($req->path === '/throw') {
        throw new \RuntimeException('boom');
    }
    if ($req->path === '/throw-error') {
        throw new \Error('bare error');
    }
    return (new \Http\Response())->text('ok ' . $req->path . "\n");
}

$port = 0;
$listener = false;
for ($p = 50100; $p < 50180; $p = $p + 1) {
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

$server = \Http\Server::onListener($listener)
    ->serverName('')
    ->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            return handler($req);
        });
    });

    \Async\delay(0.05);

    $c = new Client(fsockopen('127.0.0.1', $port));
    $c->send(get('/ok'));
    show('ok', $c->readOne());
    $c->send(get('/throw'));
    show('throw', $c->readOne());
    $c->close();

    // The 500 closed the connection; the server must still answer a new one.
    $c2 = new Client(fsockopen('127.0.0.1', $port));
    $c2->send(get('/ok'));
    show('ok2', $c2->readOne());
    $c2->send(get('/throw-error'));
    show('throw-error', $c2->readOne());
    $c2->close();

    $c3 = new Client(fsockopen('127.0.0.1', $port));
    $c3->send(get('/ok'));
    show('ok3', $c3->readOne());
    $c3->close();

    $server->stop();
    $st = $server->stats();
    echo 'served=', $st['served'], ' errors=', $st['errors'], "\n";
});

echo "done\n";
