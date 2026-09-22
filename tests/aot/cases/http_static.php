<?php

// MANTICORE-ONLY: php has no Http\Server, so it dies before printing anything
// and difftest PHP-SKIPs the file. The expected output is written BY HAND.
//
// Server and client in ONE process on the loopback, the pattern of
// http_loopback_get.php. The fixture is a 100 000-byte file with a known
// pattern, so a sendfile WINDOW is checkable byte for byte and not just by
// length.

use function Async\async;
use function Async\spawn;

$pid = (string)getmypid();
$root = rtrim(sys_get_temp_dir(), '/') . '/mc_static_' . $pid;
mkdir($root, 0777, true);
$fixture = '';
for ($i = 0; $i < 100000; $i = $i + 1) {
    $fixture .= chr($i % 253);
}
file_put_contents($root . '/big.bin', $fixture);
file_put_contents($root . '/a.txt', 'hello');
$pub = realpath($root);

$port = 0;
$listener = false;
for ($p = 49600; $p < 49680; $p = $p + 1) {
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
            $chunk = fread($this->c, 65536);
            if ($chunk === '') {
                $out = $this->buf;
                $this->buf = '';
                return $out;
            }
            $this->buf .= $chunk;
        }
    }

    public function close(): void
    {
        fclose($this->c);
    }
}

function headOf(string $raw): string
{
    $end = strpos($raw, "\r\n\r\n");
    return $end === false ? $raw : substr($raw, 0, $end);
}

function bodyOf(string $raw): string
{
    $end = strpos($raw, "\r\n\r\n");
    return $end === false ? '' : substr($raw, $end + 4);
}

function headerOf(string $raw, string $name): string
{
    foreach (explode("\r\n", headOf($raw)) as $line) {
        if (stripos($line, $name . ':') === 0) {
            return trim(substr($line, strlen($name) + 1));
        }
    }
    return '';
}

/**
 * Status line plus the headers this case pins. `date`, `last-modified` and
 * `etag` are real time or derived from it — pinned as present, never as a
 * value. The body is pinned by LENGTH and by whether it equals the window of
 * the fixture the label expects.
 */
function show(string $label, string $raw, string $want): void
{
    $lines = explode("\r\n", headOf($raw));
    $body = bodyOf($raw);
    echo $label, ': ', $lines[0], "\n";
    foreach ($lines as $i => $line) {
        if ($i === 0) {
            continue;
        }
        $lower = strtolower($line);
        if (strncmp($lower, 'date:', 5) === 0 || strncmp($lower, 'last-modified:', 14) === 0
            || strncmp($lower, 'etag:', 5) === 0) {
            $c = strpos($line, ':');
            echo '  ', substr($line, 0, $c + 1), ' present', "\n";
            continue;
        }
        echo '  ', $line, "\n";
    }
    echo '  body[', strlen($body), '] match=', $body === $want ? 'yes' : 'no', "\n";
}

$server = \Http\Server::onListener($listener)
    ->serverName('mc-test')
    ->acceptWait(0.02);

async(function () use ($server, $port, $pub, $fixture) {
    spawn(function () use ($server, $pub) {
        $server->serve(function (\Http\Request $req) use ($pub): \Http\Response {
            if ($req->path === '/throws') {
                return (new \Http\Response())->file($pub . '/nope.bin');
            }
            $p = \Http\safePath($pub, $req->path);
            if ($p === null) {
                return (new \Http\Response(404))->text('nf');
            }
            if ($req->query('status') === '201') {
                return (new \Http\Response(201))->file($p);
            }
            return (new \Http\Response())->file($p);
        });
    });

    \Async\delay(0.05);

    $sock = fsockopen('127.0.0.1', $port);
    if ($sock === false) {
        echo "connect failed\n";
        return;
    }
    $c = new Client($sock);

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\n\r\n");
    $first = $c->readOne();
    show('get', $first, $fixture);

    $c->send("HEAD /big.bin HTTP/1.1\r\nHost: t\r\n\r\n");
    show('head', $c->readOne(false), '');

    $c->send("GET /a.txt HTTP/1.1\r\nHost: t\r\n\r\n");
    show('small', $c->readOne(), 'hello');

    $c->send("GET /missing.bin HTTP/1.1\r\nHost: t\r\n\r\n");
    show('nf', $c->readOne(), 'nf');

    // The validators of the fixture, as the first response reported them.
    $etag = headerOf($first, 'ETag');
    $lm = headerOf($first, 'Last-Modified');

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nIf-None-Match: " . $etag . "\r\n\r\n");
    show('inm-hit', $c->readOne(), '');

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nIf-None-Match: *\r\n\r\n");
    show('inm-star', $c->readOne(), '');

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nIf-None-Match: \"x\", W/\"y\", " . $etag . "\r\n\r\n");
    show('inm-list', $c->readOne(), '');

    $c->send("GET /a.txt HTTP/1.1\r\nHost: t\r\nIf-None-Match: \"zzz\"\r\n\r\n");
    show('inm-miss', $c->readOne(), 'hello');

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nIf-Modified-Since: " . $lm . "\r\n\r\n");
    show('ims-hit', $c->readOne(), '');

    $c->send("GET /a.txt HTTP/1.1\r\nHost: t\r\nIf-Modified-Since: Mon, 01 Jan 1990 00:00:00 GMT\r\n\r\n");
    show('ims-old', $c->readOne(), 'hello');

    // If-None-Match present and missing: the date is never consulted.
    $c->send("GET /a.txt HTTP/1.1\r\nHost: t\r\nIf-None-Match: \"zzz\"\r\nIf-Modified-Since: " . $lm . "\r\n\r\n");
    show('ims-with-inm-miss', $c->readOne(), 'hello');

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=10-19\r\n\r\n");
    show('range-mid', $c->readOne(), substr($fixture, 10, 10));

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=-5\r\n\r\n");
    show('range-suffix', $c->readOne(), substr($fixture, 99995, 5));

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=99990-\r\n\r\n");
    show('range-open', $c->readOne(), substr($fixture, 99990, 10));

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=100000-\r\n\r\n");
    show('range-bad', $c->readOne(), '');

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=0-1,5-6\r\n\r\n");
    show('range-multi', $c->readOne(), $fixture);

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nRange: pages=1\r\n\r\n");
    show('range-garbage', $c->readOne(), $fixture);

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=0-1\r\nIf-Range: \"zzz\"\r\n\r\n");
    show('if-range-miss', $c->readOne(), $fixture);

    // Our ETags are WEAK, and a weak tag never satisfies If-Range (RFC 9110
    // §13.1.5) — the full representation is the correct answer here.
    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=0-1\r\nIf-Range: " . $etag . "\r\n\r\n");
    show('if-range-etag', $c->readOne(), $fixture);

    $c->send("GET /big.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=0-1\r\nIf-Range: " . $lm . "\r\n\r\n");
    show('if-range-date', $c->readOne(), substr($fixture, 0, 2));

    $c->send("HEAD /big.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=0-9\r\n\r\n");
    show('head-range', $c->readOne(false), '');

    // A status the handler set itself is not the server's to turn into a 304.
    $c->send("GET /a.txt?status=201 HTTP/1.1\r\nHost: t\r\nIf-None-Match: " . $etag . "\r\n\r\n");
    show('status201', $c->readOne(), 'hello');
    $c->close();

    // A handler whose file() throws answers 500 and drops the connection, so
    // this one is asked LAST and on a connection of its own.
    $c2 = new Client(fsockopen('127.0.0.1', $port));
    $c2->send("GET /throws HTTP/1.1\r\nHost: t\r\n\r\n");
    echo 'throws: ', explode("\r\n", headOf($c2->readOne()))[0], "\n";
    $c2->close();

    $server->stop();
});

echo "done\n";

unlink($root . '/big.bin');
unlink($root . '/a.txt');
rmdir($root);
