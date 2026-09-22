<?php

// MANTICORE-ONLY. A file response over TLS: `sendfile(2)` cannot serve bytes
// that have to be encrypted, so `__mc_sendfile` answers -3 for a KIND_TLS
// connection and `writeFile()` falls back to the fseek/fread loop. This case is
// what proves that fallback carries both a whole file and a Range window.
//
// The cert is the suite's self-signed fixture for 127.0.0.1; what is under test
// is the body path, not PKI, so the client turns verification off.

use function Async\async;
use function Async\spawn;

$cert = __DIR__ . '/../fixtures/tls_localhost.pem';

$pid = (string)getmypid();
$root = rtrim(sys_get_temp_dir(), '/') . '/mc_static_tls_' . $pid;
mkdir($root, 0777, true);
$fixture = '';
for ($i = 0; $i < 100000; $i = $i + 1) {
    $fixture .= chr($i % 253);
}
file_put_contents($root . '/big.bin', $fixture);
$pub = realpath($root);

$ctx = stream_context_create([
    'ssl' => ['local_cert' => $cert, 'verify_peer' => false, 'verify_peer_name' => false],
]);

$errno = 0;
$errstr = '';
$port = 0;
$listener = false;
for ($p = 51700; $p < 51780; $p = $p + 1) {
    $s = @stream_socket_server('tls://127.0.0.1:' . $p, $errno, $errstr, 12, $ctx);
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

function readResponse(\Resource $c): string
{
    $buf = '';
    while (true) {
        $end = strpos($buf, "\r\n\r\n");
        if ($end !== false) {
            $len = 0;
            foreach (explode("\r\n", substr($buf, 0, $end)) as $line) {
                if (stripos($line, 'content-length:') === 0) {
                    $len = (int)trim(substr($line, 15));
                }
            }
            if (strlen($buf) >= $end + 4 + $len) {
                return $buf;
            }
        }
        $chunk = fread($c, 65536);
        if ($chunk === '' || $chunk === false) {
            return $buf;
        }
        $buf .= $chunk;
    }
}

function report(string $label, string $raw, string $want): void
{
    $end = strpos($raw, "\r\n\r\n");
    $head = $end === false ? $raw : substr($raw, 0, $end);
    $body = $end === false ? '' : substr($raw, $end + 4);
    $status = explode("\r\n", $head)[0];
    $code = explode(' ', $status)[1];
    echo $label, ': ', $code, ' size=', strlen($body),
        ' match=', $body === $want ? 'yes' : 'no', "\n";
}

$server = \Http\Server::onListener($listener)
    ->serverName('mc-test')
    ->acceptWait(0.02);

async(function () use ($server, $port, $pub, $fixture, $cert) {
    spawn(function () use ($server, $pub) {
        $server->serve(function (\Http\Request $req) use ($pub): \Http\Response {
            $p = \Http\safePath($pub, $req->path);
            if ($p === null) {
                return (new \Http\Response(404))->text('nf');
            }
            return (new \Http\Response())->file($p);
        });
    });

    \Async\delay(0.05);

    $cctx = stream_context_create([
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $e = 0;
    $m = '';
    $c = stream_socket_client('tls://127.0.0.1:' . (string)$port, $e, $m, 5.0, 4, $cctx);
    if ($c === false) {
        echo 'no-connect(', $m, ")\n";
        return;
    }
    fwrite($c, "GET /big.bin HTTP/1.1\r\nHost: t\r\n\r\n");
    report('full', readResponse($c), $fixture);
    fwrite($c, "GET /big.bin HTTP/1.1\r\nHost: t\r\nRange: bytes=500-999\r\n\r\n");
    report('range', readResponse($c), substr($fixture, 500, 500));
    fclose($c);

    $server->stop();
});

echo "done\n";

unlink($root . '/big.bin');
rmdir($root);
