<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// connect() against a scripted raw peer: a non-101 answer, a wrong accept, an
// unoffered subprotocol or extension are HandshakeExceptions; a masked server
// frame is a protocol error (1002 sent, closeCode 1006 — no Close came back);
// a frame in the same segment as the 101 is kept; bad schemes, a closed port
// and a user header that overrides the handshake are refused.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);

function readHead(\Resource $c): string
{
    $buf = '';
    while (strpos($buf, "\r\n\r\n") === false) {
        $chunk = fread($c, 4096);
        if ($chunk === '' || $chunk === false) { break; }
        $buf .= $chunk;
    }
    return $buf;
}

function drain(\Resource $c): void
{
    while (true) {
        $chunk = fread($c, 4096);
        if ($chunk === '' || $chunk === false) { return; }
    }
}

function peer(\Resource $l, int $i): void
{
    $c = stream_socket_accept($l, 5.0);
    if ($c === false) { return; }
    $head = readHead($c);
    $key = preg_match('/Sec-WebSocket-Key: (\S+)/i', $head, $m) === 1 ? $m[1] : '';
    $ok = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . 'Sec-WebSocket-Accept: ' . WS\acceptKey($key) . "\r\n";
    if ($i === 1) {
        fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
    } elseif ($i === 2) {
        fwrite($c, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: AAAAAAAAAAAAAAAAAAAAAAAAAAA=\r\n\r\n");
    } elseif ($i === 3) {
        fwrite($c, $ok . "Sec-WebSocket-Protocol: other\r\n\r\n");
    } elseif ($i === 4) {
        fwrite($c, $ok . "Sec-WebSocket-Extensions: x-foo\r\n\r\n");
    } elseif ($i === 5) {
        fwrite($c, $ok . "\r\n" . WS\encodeFrame(1, 'masked', true, false, "\x01\x02\x03\x04"));
    } else {
        fwrite($c, $ok . "\r\n" . WS\encodeFrame(1, 'hello', true, false, ''));
        fread($c, 4096);
        fwrite($c, "\x88\x02\x03\xe8");
    }
    drain($c);
    fclose($c);
}

function attempt(string $url, ?WS\Options $o = null, array<string, string> $headers = []): void
{
    try {
        $c = WS\connect($url, $o, $headers);
        $m = $c->receive();
        if ($m === null) {
            echo 'null code=', $c->closeCode(), "\n";
            return;
        }
        echo 'ok ', $m->data, "\n";
        $c->close();
        echo 'closed code=', $c->closeCode(), "\n";
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'WebSocket connect to ')) {
            $msg = substr($msg, 0, 32);
        }
        echo get_class($e), ': ', $msg, "\n";
    }
}

async(function () use ($l, $port) {
    $peer = spawn(function () use ($l) {
        for ($i = 1; $i <= 6; $i++) {
            peer($l, $i);
        }
    });
    $url = 'ws://127.0.0.1:' . $port . '/';
    attempt($url);
    attempt($url);
    attempt($url, (new WS\Options())->protocols(['v1']));
    attempt($url);
    attempt($url);
    attempt($url);
    $peer->await();
    fclose($l);

    attempt('http://x');
    $dead = stream_socket_server('tcp://127.0.0.1:0');
    $dn = stream_socket_get_name($dead, false);
    fclose($dead);
    attempt('ws://' . $dn . '/');
    attempt($url, null, ['Sec-WebSocket-Key' => 'x']);
});
