<?php

// MANTICORE-ONLY. Server::compression(true): which responses get deflated, and
// which are left alone — the type allowlist, the minimum size, the client's
// Accept-Encoding, an encoding the handler already chose, and the `.gz` sibling
// of a file (served only when it is not older than the file it stands for).

use function Async\async;
use function Async\spawn;

$pid = (string)getmypid();
$root = rtrim(sys_get_temp_dir(), '/') . '/mc_gzip_' . $pid;
mkdir($root, 0777, true);
$asset = str_repeat("function f(){ return 'x'; }\n", 200);
file_put_contents($root . '/app.js', $asset);
file_put_contents($root . '/app.js.gz', gzencode($asset, 6));
$stale = str_repeat("var q = 1;\n", 200);
file_put_contents($root . '/old.js', $stale);
file_put_contents($root . '/old.js.gz', gzencode($stale, 6));
touch($root . '/old.js.gz', time() - 100);
$pub = realpath($root);

$json = '{"rows":[' . substr(str_repeat('{"a":1,"b":"xyz"},', 300), 0, -1) . ']}';
$png = str_repeat("\x89PNGdata", 640);
$text = str_repeat("plain text line\n", 320);

$port = 0;
$listener = false;
for ($p = 49900; $p < 49980; $p = $p + 1) {
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

/** Status, the two headers this case is about, and whether the body decodes. */
function show(string $label, string $raw, string $want): void
{
    $enc = headerOf($raw, 'Content-Encoding');
    $vary = headerOf($raw, 'Vary');
    $body = bodyOf($raw);
    $plain = $enc === 'gzip' ? gzdecode($body) : $body;
    echo $label, ': ', explode(' ', explode("\r\n", headOf($raw))[0])[1],
        ' enc=', $enc === '' ? '-' : $enc,
        ' vary=', $vary === '' ? '-' : $vary,
        ' smaller=', $enc === 'gzip' ? (strlen($body) < strlen($want) ? 'yes' : 'no') : '-',
        ' body=', $plain === $want ? 'ok' : 'BAD', "\n";
}

$server = \Http\Server::onListener($listener)
    ->serverName('mc-test')
    ->acceptWait(0.02)
    ->compression(true);

async(function () use ($server, $port, $pub, $json, $png, $text, $asset, $stale) {
    spawn(function () use ($server, $pub, $json, $png, $text) {
        $server->serve(function (\Http\Request $req) use ($pub, $json, $png, $text): \Http\Response {
            if ($req->path === '/json') {
                return (new \Http\Response())->type('application/json')->body($json);
            }
            if ($req->path === '/small') {
                return (new \Http\Response())->type('application/json')->body('{"a":1}');
            }
            if ($req->path === '/png') {
                return (new \Http\Response())->type('image/png')->body($png);
            }
            if ($req->path === '/pre') {
                return (new \Http\Response())->type('text/plain; charset=utf-8')
                    ->header('Content-Encoding', 'identity')->body($text);
            }
            $p = \Http\safePath($pub, $req->path);
            if ($p === null) {
                return (new \Http\Response(404))->text('nf');
            }
            return (new \Http\Response())->file($p);
        });
    });

    \Async\delay(0.05);

    $c = new Client(fsockopen('127.0.0.1', $port));
    $gz = "Accept-Encoding: gzip\r\n";

    $c->send("GET /json HTTP/1.1\r\nHost: t\r\n" . $gz . "\r\n");
    $jr = $c->readOne();
    show('json', $jr, $json);

    $c->send("GET /json HTTP/1.1\r\nHost: t\r\n\r\n");
    show('json-noae', $c->readOne(), $json);

    $c->send("GET /json HTTP/1.1\r\nHost: t\r\nAccept-Encoding: gzip;q=0\r\n\r\n");
    show('json-q0', $c->readOne(), $json);

    $c->send("GET /json HTTP/1.1\r\nHost: t\r\nAccept-Encoding: *\r\n\r\n");
    show('json-star', $c->readOne(), $json);

    $c->send("GET /small HTTP/1.1\r\nHost: t\r\n" . $gz . "\r\n");
    show('small', $c->readOne(), '{"a":1}');

    $c->send("GET /png HTTP/1.1\r\nHost: t\r\n" . $gz . "\r\n");
    show('png', $c->readOne(), $png);

    $c->send("GET /pre HTTP/1.1\r\nHost: t\r\n" . $gz . "\r\n");
    show('pre', $c->readOne(), $text);

    // HEAD carries the ENCODED length of the GET it mirrors.
    $c->send("HEAD /json HTTP/1.1\r\nHost: t\r\n" . $gz . "\r\n");
    $hr = $c->readOne(false);
    echo 'head-json: ', headerOf($hr, 'Content-Encoding'),
        ' len match=', headerOf($hr, 'Content-Length') === (string)strlen(bodyOf($jr)) ? 'yes' : 'no', "\n";

    $c->send("GET /app.js HTTP/1.1\r\nHost: t\r\n" . $gz . "\r\n");
    show('file', $c->readOne(), $asset);

    $c->send("GET /app.js HTTP/1.1\r\nHost: t\r\n\r\n");
    show('file-noae', $c->readOne(), $asset);

    // The sibling is OLDER than the file it stands for: a build artefact
    // nobody refreshed, so the file itself is served.
    $c->send("GET /old.js HTTP/1.1\r\nHost: t\r\n" . $gz . "\r\n");
    show('stale', $c->readOne(), $stale);

    $c->close();
    $server->stop();
});

echo "done\n";

unlink($root . '/app.js');
unlink($root . '/app.js.gz');
unlink($root . '/old.js');
unlink($root . '/old.js.gz');
rmdir($root);
