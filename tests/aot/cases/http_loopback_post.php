<?php

// MANTICORE-ONLY (no Http\Server in php): request BODIES over a real socket —
// a declared Content-Length, a chunked body reassembled across chunk
// boundaries, `Expect: 100-continue` producing the interim response, a body
// that outgrows maxBodySize with streaming ON (handed over as a Reader) and
// OFF (413), and a form body decoded through the query parser.
// The expected output is written BY HAND.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 49460; $p < 49540; $p = $p + 1) {
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

    /** One complete response; interim 1xx replies are returned on their own. */
    public function readOne(): string
    {
        while (true) {
            $end = strpos($this->buf, "\r\n\r\n");
            if ($end !== false) {
                $head = substr($this->buf, 0, $end);
                $len = 0;
                $interim = strncmp($head, 'HTTP/1.1 1', 10) === 0;
                if (!$interim) {
                    foreach (explode("\r\n", $head) as $line) {
                        if (stripos($line, 'content-length:') === 0) {
                            $len = (int)trim(substr($line, 15));
                        }
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
    echo $label, ': ', $lines[0], ' body=', $body, "\n";
}

$server = \Http\Server::onListener($listener)
    ->serverName('')
    ->maxBodySize(64)
    ->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            if ($req->path === '/form') {
                $f = \Http\parseQuery($req->body());
                return (new \Http\Response())->text('name=' . ($f['name'] ?? '-')
                    . ' city=' . ($f['city'] ?? '-'));
            }
            if ($req->path === '/big') {
                $rd = $req->stream();
                if ($rd === null) {
                    return (new \Http\Response())->text('buffered:' . strlen($req->body()));
                }
                $n = 0;
                $first = '';
                while (!$rd->eof()) {
                    $part = $rd->read(16);
                    if ($part === '') {
                        break;
                    }
                    if ($first === '') {
                        $first = $part;
                    }
                    $n = $n + strlen($part);
                }
                return (new \Http\Response())->text('streamed:' . $n . ' head=' . $first);
            }
            return (new \Http\Response())->text('len=' . strlen($req->body())
                . ' ct=' . $req->contentType() . ' body=' . $req->body());
        });
    });

    \Async\delay(0.05);
    $c = new Client(fsockopen('127.0.0.1', $port));

    // A declared Content-Length, sent in ONE write.
    $c->send("POST /p HTTP/1.1\r\nHost: t\r\nContent-Type: text/plain\r\n"
        . "Content-Length: 11\r\n\r\nhello world");
    show('cl', $c->readOne());

    // The body arriving AFTER the head, in two pieces.
    $c->send("POST /p HTTP/1.1\r\nHost: t\r\nContent-Length: 9\r\n\r\nabcd");
    \Async\delay(0.02);
    $c->send('efghi');
    show('split', $c->readOne());

    // Chunked, with a chunk-extension and a trailer.
    $c->send("POST /p HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n"
        . "4\r\nchun\r\n3;x=1\r\nked\r\n0\r\nX-T: t\r\n\r\n");
    show('chunked', $c->readOne());

    // A form body, decoded with the same parser the query string uses.
    $body = 'name=ada+lovelace&city=London';
    $c->send("POST /form HTTP/1.1\r\nHost: t\r\n"
        . "Content-Type: application/x-www-form-urlencoded\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
    show('form', $c->readOne());

    // Expect: 100-continue — the interim response comes first, on its own.
    $c->send("POST /p HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\nExpect: 100-continue\r\n\r\n");
    show('interim', $c->readOne());
    $c->send('later');
    show('expect', $c->readOne());

    // An Expect nobody implements is a 417, and the body is never invited.
    $c->send("POST /p HTTP/1.1\r\nHost: t\r\nContent-Length: 2\r\nExpect: the-moon\r\n\r\n");
    show('expect-bad', $c->readOne());
    $c->close();

    // Over maxBodySize with streaming OFF: refused before a byte is read.
    $c2 = new Client(fsockopen('127.0.0.1', $port));
    $c2->send("POST /big HTTP/1.1\r\nHost: t\r\nContent-Length: 100\r\n\r\n");
    show('too-big', $c2->readOne());
    $c2->close();

    // …and with streaming ON the same request is handed over as a Reader.
    $server->streamBodies(true);
    $c3 = new Client(fsockopen('127.0.0.1', $port));
    $payload = str_repeat('0123456789', 10);
    $c3->send("POST /big HTTP/1.1\r\nHost: t\r\nContent-Length: 100\r\n\r\n" . $payload);
    show('streamed', $c3->readOne());
    $c3->close();

    $server->stop();
    $st = $server->stats();
    echo 'served=', $st['served'], ' errors=', $st['errors'], "\n";
});

echo "done\n";
