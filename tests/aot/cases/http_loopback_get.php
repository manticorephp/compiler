<?php

// MANTICORE-ONLY: php has no Http\Server, so it dies before printing anything
// and difftest PHP-SKIPs the file. The expected output is written BY HAND.
//
// Server and client in ONE process, on the loopback: the server loop is a task
// of the same Async scope as the client, which is exactly what Server::serve()
// being reentrant buys — without it a case would need a second process, and
// there is no fork() here to make one.
//
// The port is SCANNED and never printed (it is arbitrary in any runtime); the
// `@` matters, since php warns to STDOUT on a failed bind.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 49380; $p < 49460; $p = $p + 1) {
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

/**
 * One connection with its own leftover buffer.
 *
 * The buffer is the point: a pipelined pair arrives in one read, so a client
 * that threw the remainder away would lose the second response — the same
 * reason the server keeps one. `$expectBody` is false for HEAD, whose
 * Content-Length describes a body it does not send.
 */
final class Client
{
    private string $buf = '';

    public function __construct(private \Resource $c) {}

    public function send(string $raw): void
    {
        fwrite($this->c, $raw);
    }

    public function readOne(bool $expectBody = true): string
    {
        while (true) {
            $end = strpos($this->buf, "\r\n\r\n");
            if ($end !== false) {
                $len = 0;
                if ($expectBody) {
                    foreach (explode("\r\n", substr($this->buf, 0, $end)) as $line) {
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

    public function readRaw(int $n): string
    {
        if ($this->buf !== '') {
            $out = $this->buf;
            $this->buf = '';
            return $out;
        }
        return fread($this->c, $n);
    }

    public function close(): void
    {
        fclose($this->c);
    }
}

/** The status line plus the headers this case pins, in a stable order. */
function show(string $label, string $raw): void
{
    $end = strpos($raw, "\r\n\r\n");
    $head = $end === false ? $raw : substr($raw, 0, $end);
    $body = $end === false ? '' : substr($raw, $end + 4);
    $lines = explode("\r\n", $head);
    echo $label, ': ', $lines[0], "\n";
    foreach ($lines as $i => $line) {
        if ($i === 0) {
            continue;
        }
        $lower = strtolower($line);
        // Date: is real time — pinned as present, never as a value.
        if (strncmp($lower, 'date:', 5) === 0) {
            echo '  date: ', strlen($line) > 6 ? 'present' : 'EMPTY', "\n";
            continue;
        }
        echo '  ', $line, "\n";
    }
    echo '  body[', strlen($body), ']=', $body, "\n";
}

$server = \Http\Server::onListener($listener)
    ->serverName('mc-test')
    ->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            if ($req->path === '/echo') {
                return (new \Http\Response())
                    ->text('m=' . $req->method . ' q=' . $req->query('x', '-')
                        . ' ua=' . $req->header('User-Agent', '-'));
            }
            if ($req->path === '/created') {
                return (new \Http\Response(201))->type('application/json')->body('{"ok":true}');
            }
            if ($req->path === '/empty') {
                return (new \Http\Response(204));
            }
            if ($req->path === '/bye') {
                return (new \Http\Response())->text('bye')->close();
            }
            return (new \Http\Response(404))->text('nope');
        });
    });

    // Give the accept loop a turn before connecting.
    \Async\delay(0.05);

    $sock = fsockopen('127.0.0.1', $port);
    if ($sock === false) {
        echo "connect failed\n";
        return;
    }
    $c = new Client($sock);
    $c->send("GET /echo?x=1 HTTP/1.1\r\nHost: t\r\nUser-Agent: probe\r\n\r\n");
    show('first', $c->readOne());

    // The SAME connection answers a second request — keep-alive.
    $c->send("GET /created HTTP/1.1\r\nHost: t\r\n\r\n");
    show('second', $c->readOne());

    // 204 carries no body and no Content-Length.
    $c->send("GET /empty HTTP/1.1\r\nHost: t\r\n\r\n");
    show('empty', $c->readOne());

    // HEAD keeps the headers of the GET it mirrors, body dropped.
    $c->send("HEAD /echo HTTP/1.1\r\nHost: t\r\n\r\n");
    show('head', $c->readOne(false));

    $c->send("GET /missing HTTP/1.1\r\nHost: t\r\n\r\n");
    show('missing', $c->readOne());

    // A handler asking to close ends the connection after answering.
    $c->send("GET /bye HTTP/1.1\r\nHost: t\r\n\r\n");
    show('bye', $c->readOne());
    echo 'after close: [', $c->readRaw(64), "]\n";
    $c->close();

    // A client asking to close gets Connection: close on a fresh connection.
    $c2 = new Client(fsockopen('127.0.0.1', $port));
    $c2->send("GET /echo HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
    show('closing', $c2->readOne());
    $c2->close();

    // Two complete requests in ONE write: both answered, in order, and the
    // second is parsed from the buffer with no further read.
    $c3 = new Client(fsockopen('127.0.0.1', $port));
    $c3->send("GET /echo?x=A HTTP/1.1\r\nHost: t\r\n\r\nGET /echo?x=B HTTP/1.1\r\nHost: t\r\n\r\n");
    show('pipe1', $c3->readOne());
    show('pipe2', $c3->readOne());
    $c3->close();

    $server->stop();
    $st = $server->stats();
    echo 'served=', $st['served'], ' accepted=', $st['accepted'],
        ' errors=', $st['errors'], ' stopped=', $st['stopped'], "\n";
});

echo "done\n";
