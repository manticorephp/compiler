<?php

// MANTICORE-ONLY (no Http\Server in php). Every request runs its handler inside
// a per-request scope (Context::withValue(CTX_REQUEST, …) opens a TaskGroup),
// and closing that scope is `$cur->scope = $group->parent`. Task::scope was a
// BORROWED slot — one of its reads handed the pointer out — so the store never
// released the group it overwrote, and the group kept the whole Http\Request
// tree alive through its `values`: ~1.7 KB per request, 33.8 MB over 20 000
// keep-alive GETs with compat off.
//
// memory_get_usage() answers the peak RSS (ru_maxrss), which a leak can only
// raise: one keep-alive client drives 1 000 warm-up requests, then 20 000
// measured ones, and the growth over the measured run is the pin. The bound is
// peak RSS, not live bytes: the fixed tree measures ~2.8 MB here, of which
// ~1.6 MB is one ~64-byte string per request still unreleased and the rest is
// allocator slack. @serial: a memory measurement, not a race with nine other
// cases.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 52600; $p < 52680; $p = $p + 1) {
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

/** One keep-alive connection; answers the body of the next response. */
final class Client
{
    private string $buf = '';

    public function __construct(private \Resource $c) {}

    public function call(string $raw): string
    {
        fwrite($this->c, $raw);
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
                    $body = substr($this->buf, $end + 4, $len);
                    $this->buf = substr($this->buf, $whole);
                    return $body;
                }
            }
            $chunk = fread($this->c, 4096);
            if ($chunk === '') {
                return 'EOF';
            }
            $this->buf .= $chunk;
        }
    }

    public function close(): void
    {
        fclose($this->c);
    }
}

$server = \Http\Server::onListener($listener)
    ->serverName('')
    ->keepAliveMax(100000)
    ->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            return (new \Http\Response())->text(
                $req->method . ' ' . $req->path . ' ' . count($req->queryArray()) . ' ' . $req->header('host'),
            );
        });
    });

    \Async\delay(0.05);

    $sock = fsockopen('127.0.0.1', $port);
    if ($sock === false) {
        echo "connect failed\n";
        return;
    }
    $c = new Client($sock);
    $raw = "GET /p?a=1&b=2&c=3 HTTP/1.1\r\nHost: t\r\nCookie: x=1; y=2; z=3\r\n\r\n";
    $want = 'GET /p 3 t';
    echo 'first=', $c->call($raw), "\n";

    for ($i = 0; $i < 1000; $i = $i + 1) {
        $c->call($raw);
    }
    $before = memory_get_usage();
    $n = 20000;
    $bad = 0;
    for ($i = 0; $i < $n; $i = $i + 1) {
        if ($c->call($raw) !== $want) {
            $bad = $bad + 1;
        }
    }
    $growth = memory_get_usage() - $before;
    $c->close();

    echo 'bad=', $bad, "\n";
    if ($growth < 4 * 1024 * 1024) {
        echo "growth ok\n";
    } else {
        echo 'growth=', round($growth / 1048576, 1), "MB over ", $n, " requests\n";
    }

    $server->stop();
    echo 'served=', $server->stats()['served'], "\n";
});

echo "done\n";
