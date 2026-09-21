<?php

// MANTICORE-ONLY (no Http\Server in php). compat(true) seeds $_GET, $_COOKIE
// and $_REQUEST element by element on EVERY request, and each request starts
// by resetting them (`$_GET = Context::$empty`). That reset is a whole-array
// store into a superglobal cell, and the cell never released what it held:
// ~80 B per seeded element per request, 67 MB at 200k.
//
// memory_get_usage() answers the peak RSS here (ru_maxrss), and a leak only
// ever raises it — so the pin is DIFFERENTIAL: the same keep-alive client
// drives 20 000 GETs with compat off, then 20 000 with compat on, and what the
// second run adds beyond the first is what the seeding costs. That keeps the
// server's own per-request footprint out of the number. The leak was ~10 MB
// per 20 000; the threshold leaves room for allocator slack.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 49860; $p < 49940; $p = $p + 1) {
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

/** Peak-RSS growth over $n requests after a warm-up, and how many answered wrong. */
function drive(Client $c, string $raw, string $want, int $n): array<int, int>
{
    for ($i = 0; $i < 500; $i = $i + 1) {
        $c->call($raw);
    }
    $before = memory_get_usage();
    $bad = 0;
    for ($i = 0; $i < $n; $i = $i + 1) {
        if ($c->call($raw) !== $want) {
            $bad = $bad + 1;
        }
    }
    return [memory_get_usage() - $before, $bad];
}

$server = \Http\Server::onListener($listener)
    ->serverName('')
    ->keepAliveMax(100000)
    ->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            // Both runs parse the query, the cookies and the (empty) form the
            // way the seeding does, so the two differ in the seeding alone.
            $own = count($req->queryArray()) . $req->cookie('y', '-') . count($req->postArray());
            return (new \Http\Response())->text(
                $own . '|' . ($_GET['a'] ?? '-') . ($_COOKIE['y'] ?? '-') . ($_REQUEST['c'] ?? '-')
                . count($_GET) . count($_COOKIE) . count($_REQUEST),
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
    $n = 20000;

    echo 'plain=', $c->call($raw), "\n";
    $plain = drive($c, $raw, '320|---000', $n);
    echo 'plain bad=', $plain[1], "\n";

    $server->compat(true);
    echo 'compat=', $c->call($raw), "\n";
    $compat = drive($c, $raw, '320|123333', $n);
    echo 'compat bad=', $compat[1], "\n";
    $c->close();

    $extra = $compat[0] - $plain[0];
    if ($extra < 2 * 1024 * 1024) {
        echo "compat growth ok\n";
    } else {
        echo 'compat growth=', round($extra / 1048576, 1), "MB over ", $n, " requests\n";
    }

    $server->stop();
    echo 'served=', $server->stats()['served'], "\n";
});

echo "done\n";
