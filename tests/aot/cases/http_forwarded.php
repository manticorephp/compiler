<?php

// MANTICORE-ONLY. Expected output by hand. Ports scanned, never printed.

use function Async\async;
use function Async\spawn;

function listen(int $from): array<int, mixed>
{
    for ($p = $from; $p < $from + 60; $p = $p + 1) {
        $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
        if ($s !== false) { stream_set_blocking($s, false); return [$s, $p]; }
    }
    return [false, 0];
}

function readOne(\Resource $c): string
{
    $buf = '';
    while (true) {
        $end = strpos($buf, "\r\n\r\n");
        if ($end !== false) {
            $len = 0;
            foreach (explode("\r\n", substr($buf, 0, $end)) as $line) {
                if (stripos($line, 'content-length:') === 0) { $len = (int)trim(substr($line, 15)); }
            }
            if (strlen($buf) >= $end + 4 + $len) { return substr($buf, $end + 4, $len); }
        }
        $chunk = fread($c, 4096);
        if ($chunk === '') { return ''; }
        $buf .= $chunk;
    }
}

/** The socket answers 'ip:port' and the port is not stable; a forwarded value carries none. */
function ipOnly(string $hp): string
{
    $close = strrpos($hp, ']');
    if ($close !== false) { return trim(substr($hp, 0, $close + 1), '[]'); }
    if (substr_count($hp, ':') === 1) { return substr($hp, 0, strpos($hp, ':')); }
    return $hp;
}

function ask(int $port, string $extraHeaders): string
{
    $c = fsockopen('127.0.0.1', $port);
    fwrite($c, "GET /x HTTP/1.1\r\nHost: origin.local:8080\r\n" . $extraHeaders . "\r\n");
    $out = readOne($c);
    fclose($c);
    return $out;
}

$handler = function (\Http\Request $req): \Http\Response {
    $peer = ipOnly($req->peerAddr);
    return (new \Http\Response())->text(
        'remote=' . ipOnly($req->remoteAddr)
        . ' peer=' . $peer
        . ' secure=' . ($req->secure ? '1' : '0')
        . ' host=' . $req->header('Host')
        . ' fport=' . $req->forwardedPort
        . ' S.addr=' . $_SERVER['REMOTE_ADDR']
        . ' S.https=' . $_SERVER['HTTPS']
        . ' S.name=' . $_SERVER['SERVER_NAME']
        . ' S.port=' . $_SERVER['SERVER_PORT']
    );
};

[$l1, $p1] = listen(49620);
[$l2, $p2] = listen(49680);
[$l3, $p3] = listen(49740);
[$l4, $p4] = listen(49800);
[$l5, $p5] = listen(49860);
if ($l1 === false || $l2 === false || $l3 === false || $l4 === false || $l5 === false) { echo "no free port\n"; return; }

$untrusted = \Http\Server::onListener($l1)->acceptWait(0.02)->compat(true);
$trusted = \Http\Server::onListener($l2)->acceptWait(0.02)->compat(true)
    ->trustedProxies(['127.0.0.1', '10.0.0.0/8']);
$forOnly = \Http\Server::onListener($l3)->acceptWait(0.02)->compat(true)
    ->trustedProxies(['127.0.0.0/8'], \Http\Proxy::FOR);
$fwd = \Http\Server::onListener($l4)->acceptWait(0.02)->compat(true)
    ->trustedProxies(['127.0.0.1', '10.0.0.0/8'], \Http\Proxy::ALL | \Http\Proxy::FORWARDED);
$forFwd = \Http\Server::onListener($l5)->acceptWait(0.02)->compat(true)
    ->trustedProxies(['127.0.0.0/8'], \Http\Proxy::FOR | \Http\Proxy::FORWARDED);

async(function () use ($untrusted, $trusted, $forOnly, $fwd, $forFwd, $handler, $p1, $p2, $p3, $p4, $p5) {
    spawn(function () use ($untrusted, $handler) { $untrusted->serve($handler); });
    spawn(function () use ($trusted, $handler) { $trusted->serve($handler); });
    spawn(function () use ($forOnly, $handler) { $forOnly->serve($handler); });
    spawn(function () use ($fwd, $handler) { $fwd->serve($handler); });
    spawn(function () use ($forFwd, $handler) { $forFwd->serve($handler); });
    \Async\delay(0.05);

    $xf = "X-Forwarded-For: 203.0.113.9, 10.1.1.1\r\nX-Forwarded-Proto: https\r\nX-Forwarded-Host: app.example\r\nX-Forwarded-Port: 443\r\n";

    echo 'untrusted: ', ask($p1, $xf), "\n";
    echo 'trusted:   ', ask($p2, $xf), "\n";
    echo 'no hdrs:   ', ask($p2, ''), "\n";
    echo 'all hops:  ', ask($p2, "X-Forwarded-For: 10.2.2.2, 10.1.1.1\r\n"), "\n";
    echo 'v6 for:    ', ask($p2, "X-Forwarded-For: 2001:db8::7\r\n"), "\n";
    echo 'rfc7239:   ', ask($p4, "Forwarded: for=\"[2001:db8::1]:4711\";proto=https;host=rfc.example\r\n"), "\n";
    echo 'both:      ', ask($p4, "Forwarded: for=198.51.100.4\r\n" . $xf), "\n";
    echo 'for only:  ', ask($p3, $xf), "\n";
    echo 'unknown hop: ', ask($p2, "X-Forwarded-For: unknown, 10.1.1.1\r\n"), "\n";
    echo 'obf hop:   ', ask($p2, "X-Forwarded-For: 203.0.113.9, _gazonk\r\n"), "\n";
    echo 'only junk: ', ask($p2, "X-Forwarded-For: unknown\r\n"), "\n";
    echo 'fwd default off: ', ask($p2, "Forwarded: for=1.1.1.1\r\nX-Forwarded-For: 203.0.113.9\r\n"), "\n";
    echo 'port range: ', ask($p2, "X-Forwarded-For: 203.0.113.9\r\nX-Forwarded-Proto: https\r\nX-Forwarded-Host: app.example\r\nX-Forwarded-Port: 0000099999\r\n"), "\n";
    echo 'fwd chain: ', ask($p4, "Forwarded: for=1.1.1.1, for=203.0.113.9;proto=https;host=h.example, for=10.1.1.1;proto=http\r\n"), "\n";
    echo 'fwd for-only: ', ask($p5, "Forwarded: for=203.0.113.9;proto=https;host=x.example\r\n"), "\n";

    $h = new \Http\Headers();
    $h->set('X-Forwarded-For', '203.0.113.9');
    $r = \Http\resolveForwarded($h, '::1:4321', false, [\Http\cidrParse('::1')], \Http\Proxy::ALL);
    echo 'v6 peer: remote=', $r[0], "\n";
    echo 'v6 peer addr: ', \Http\peerIp('::1:4321'), ' ', \Http\peerIp('127.0.0.1:80'), ' ', \Http\peerIp('[::1]:80'), "\n";

    $untrusted->stop();
    $trusted->stop();
    $forOnly->stop();
    $fwd->stop();
    $forFwd->stop();
});
echo "done\n";
