<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// permessage-deflate (RFC 7692): negotiation of all four parameters, the §7.2.3
// "Hello" vectors (one-off and shared window), context takeover on the server's
// deflater and its absence, server_max_window_bits honoured by the encoder (the
// raw side inflates with that window, which rejects any farther distance),
// window 8 declined where it would bind our deflater (server skips the offer,
// client fails the handshake), compressionMinBytes, an inflation bomb (1009), a
// corrupt payload (1007), RSV1 where it may not be (1002), and our client both
// with and without the server.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

/** A raw WebSocket client: masks what it sends, reads frames (RSV1 allowed) unmasked. */
final class Raw
{
    public string $buf = '';
    public string $head = '';
    public bool $rsv1 = false;
    public string $payload = '';

    public function __construct(public \Resource $c) {}

    public static function open(int $port, string $path, string $extra = ''): Raw
    {
        $c = stream_socket_client('tcp://127.0.0.1:' . $port);
        fwrite($c, "GET $path HTTP/1.1\r\nHost: t\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n" . $extra . "\r\n");
        $r = new Raw($c);
        $r->head = $r->until("\r\n\r\n");
        return $r;
    }

    public function until(string $needle): string
    {
        while (strpos($this->buf, $needle) === false) {
            $chunk = fread($this->c, 65536);
            if ($chunk === '' || $chunk === false) { return ''; }
            $this->buf .= $chunk;
        }
        $p = strpos($this->buf, $needle) + strlen($needle);
        $out = substr($this->buf, 0, $p);
        $this->buf = substr($this->buf, $p);
        return $out;
    }

    /** The response's Sec-WebSocket-Extensions value, lowercased; '' when absent. */
    public function ext(): string
    {
        foreach (explode("\r\n", strtolower($this->head)) as $line) {
            if (strncmp($line, 'sec-websocket-extensions:', 25) === 0) {
                return trim(substr($line, 25));
            }
        }
        return '';
    }

    public function send(int $op, string $payload, bool $fin = true, int $rsvBits = 0): void
    {
        $f = \Http\WebSocket\encodeFrame($op, $payload, $fin, false, "\x11\x22\x33\x44");
        fwrite($this->c, chr(ord($f[0]) | $rsvBits) . substr($f, 1));
    }

    /** Next frame as "op:payload" (close → "8:<code>:<reason>"), or "EOF"; rsv1/payload kept. */
    public function frame(): string
    {
        $b = new \Buffer\ByteBuffer();
        $b->append($this->buf);
        $p = new \Http\WebSocket\FrameParser($b, false, 1 << 26, true);
        while (($r = $p->parse()) === \Http\WebSocket\FrameParser::NEED) {
            $chunk = fread($this->c, 65536);
            if ($chunk === '' || $chunk === false) { return 'EOF'; }
            $b->append($chunk);
        }
        $this->buf = $b->view();
        if ($r !== \Http\WebSocket\FrameParser::FRAME) { return 'bad' . $r; }
        $this->rsv1 = $p->rsv1;
        $this->payload = $p->payload;
        if ($p->opcode === 8) {
            $code = strlen($p->payload) >= 2 ? (ord($p->payload[0]) << 8) | ord($p->payload[1]) : 0;
            return '8:' . $code . ':' . substr($p->payload, 2);
        }
        return $p->opcode . ':' . (strlen($p->payload) > 32 ? strlen($p->payload) . 'B' : $p->payload);
    }
}

/** Inflate one compressed message payload through $ctx (the raw side's shared window). */
function unz(\InflateContext $ctx, string $payload): string
{
    $out = inflate_add($ctx, $payload . "\x00\x00\xff\xff", ZLIB_SYNC_FLUSH);
    return $out === false ? 'CORRUPT' : $out;
}

function zmsg(string $plain): string
{
    $z = deflate_add(deflate_init(ZLIB_ENCODING_RAW), $plain, ZLIB_SYNC_FLUSH);
    return substr($z, 0, strlen($z) - 4);
}

/** 10 MB of zeros as one compressed message, deflated in 100 KB steps (one 10 MB call peaks at ~600 MB). */
function zbomb(): string
{
    $d = deflate_init(ZLIB_ENCODING_RAW);
    $zero = str_repeat("\0", 100000);
    $z = '';
    for ($i = 0; $i < 99; $i++) {
        $z .= deflate_add($d, $zero, ZLIB_NO_FLUSH);
    }
    $z .= deflate_add($d, $zero, ZLIB_SYNC_FLUSH);
    return substr($z, 0, strlen($z) - 4);
}

/** A one-shot raw server answering the upgrade with $ext; what our client's connect() makes of it. */
function fakeServer(string $ext): string
{
    $l = stream_socket_server('tcp://127.0.0.1:0');
    $n = stream_socket_get_name($l, false);
    $port = (int)substr($n, strrpos($n, ':') + 1);
    $t = spawn(function () use ($l, $ext): void {
        $s = stream_socket_accept($l, 5);
        $head = '';
        while (strpos($head, "\r\n\r\n") === false) {
            $chunk = fread($s, 8192);
            if ($chunk === '' || $chunk === false) { break; }
            $head .= $chunk;
        }
        preg_match('/Sec-WebSocket-Key: (\S+)/i', $head, $m);
        fwrite($s, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . \Http\WebSocket\acceptKey($m[1]) . "\r\nSec-WebSocket-Extensions: " . $ext . "\r\n\r\n");
        fclose($s);
    });
    try {
        $c = WS\connect('ws://127.0.0.1:' . $port . '/', (new WS\Options())->compression(true)->closeTimeout(0.5));
        $out = 'connected';
        $c->close();
    } catch (WS\HandshakeException $e) {
        $out = $e->getMessage();
    }
    $t->await();
    fclose($l);
    return $out;
}

$l = stream_socket_server('tcp://127.0.0.1:0');
stream_set_blocking($l, false);
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
$server = \Http\Server::onListener($l)->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            $o = new WS\Options();
            if ($req->path === '/z0') {
                $o->compression(true)->compressionMinBytes(0);
            } elseif ($req->path === '/z') {
                $o->compression(true);
            } elseif ($req->path === '/small') {
                $o->compression(true)->maxMessageSize(100000);
            }
            return WS\upgrade($req, function (WS\Connection $ws): void {
                while (($m = $ws->receive()) !== null) {
                    $m->binary ? $ws->sendBinary($m->data) : $ws->send('echo:' . $m->data);
                }
            }, $o);
        });
    });
    $offer = "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n";

    // 1. RFC 7692 §7.2.3 vectors, takeover on the server's deflater, an uncompressed frame between.
    $r = Raw::open($port, '/z0', $offer);
    echo 'ext: ', $r->ext(), "\n";
    $inf = inflate_init(ZLIB_ENCODING_RAW);
    $r->send(1, "\xf2\x48\xcd\xc9\xc9\x07\x00", true, 0x40);
    $r->frame();
    $first = strlen($r->payload);
    echo $r->rsv1 ? 'rsv1 ' : 'plain ', unz($inf, $r->payload), "\n";
    $r->send(1, "\xf2\x00\x11\x00\x00", true, 0x40);
    $r->frame();
    echo $r->rsv1 ? 'rsv1 ' : 'plain ', unz($inf, $r->payload), "\n";
    echo strlen($r->payload) < $first ? 'server takeover' : 'no server takeover', "\n";
    $r->send(1, 'tiny');
    $r->frame();
    echo $r->rsv1 ? 'rsv1 ' : 'plain ', unz($inf, $r->payload), "\n";
    fclose($r->c);

    // compressionMinBytes (256 by default): a short reply goes plain, a long one compressed.
    $r = Raw::open($port, '/z', $offer);
    $inf = inflate_init(ZLIB_ENCODING_RAW);
    $r->send(1, str_repeat('s', 100));
    $r->frame();
    echo 'short reply ', $r->rsv1 ? 'compressed' : 'plain', ' ', strlen($r->payload), "\n";
    $r->send(1, str_repeat('L', 300));
    $r->frame();
    echo 'long reply ', $r->rsv1 ? 'compressed' : 'plain', ' ', strlen(unz($inf, $r->payload)), "\n";
    fclose($r->c);

    // 2. Our client to our server, compression on both ends.
    $c = WS\connect('ws://127.0.0.1:' . $port . '/z', (new WS\Options())->compression(true));
    $msg = str_repeat('abcdefgh', 500);
    $ok = 0;
    for ($i = 0; $i < 50; $i++) {
        $c->send($msg . $i);
        if ($c->receive()->data === 'echo:' . $msg . $i) {
            $ok++;
        }
    }
    $c->send('tiny');
    echo $ok, ' ok, ', $c->receive()->data, "\n";
    $c->close();

    // 3. No context takeover on either side.
    $r = Raw::open($port, '/z0', "Sec-WebSocket-Extensions: permessage-deflate; server_no_context_takeover; client_no_context_takeover\r\n");
    echo 'ext: ', $r->ext(), "\n";
    $inf = inflate_init(ZLIB_ENCODING_RAW);
    $same = str_repeat('same message ', 20);
    $r->send(1, zmsg($same), true, 0x40);
    $r->frame();
    $a = strlen($r->payload);
    $ea = unz($inf, $r->payload);
    $r->send(1, zmsg($same), true, 0x40);
    $r->frame();
    $eb = unz($inf, $r->payload);
    echo $a === strlen($r->payload) && $ea === $eb && $ea === 'echo:' . $same ? 'no takeover' : 'takeover?', "\n";
    fclose($r->c);

    // 4. server_max_window_bits=10: the raw side inflates with a 1 KiB window.
    $r = Raw::open($port, '/z0', "Sec-WebSocket-Extensions: permessage-deflate; server_max_window_bits=10, permessage-deflate\r\n");
    echo 'ext: ', $r->ext(), "\n";
    $inf = inflate_init(ZLIB_ENCODING_RAW, ['window' => 10]);
    mt_srand(7);
    $block = '';
    for ($i = 0; $i < 4096; $i++) {
        $block .= chr(mt_rand(0, 255));
    }
    $big = str_repeat($block, 5);
    $r->send(2, $big);
    $r->frame();
    echo $r->rsv1 && unz($inf, $r->payload) === $big ? 'window ok' : 'window BAD', "\n";
    fclose($r->c);

    // Window 8: an offer binding the server's deflater to 8 is skipped (never answered 9);
    // the client's own limit of 8 is accepted, and a compressed message from it inflates.
    $r = Raw::open($port, '/z0', "Sec-WebSocket-Extensions: permessage-deflate; server_max_window_bits=8, permessage-deflate; client_max_window_bits=8\r\n");
    echo 'ext: ', $r->ext(), "\n";
    $r->send(1, "\xf2\x48\xcd\xc9\xc9\x07\x00", true, 0x40);
    $r->frame();
    echo 'w8 ', unz(inflate_init(ZLIB_ENCODING_RAW), $r->payload), "\n";
    fclose($r->c);
    $r = Raw::open($port, '/z0', "Sec-WebSocket-Extensions: permessage-deflate; server_max_window_bits=8\r\n");
    echo 'ext: [', $r->ext(), "]\n";
    fclose($r->c);
    echo 'client w8: ', fakeServer("permessage-deflate; client_max_window_bits=8"), "\n";
    echo 'client server-w8: ', fakeServer("permessage-deflate; server_max_window_bits=8"), "\n";

    // A malformed offer is skipped; the next acceptable one wins.
    $r = Raw::open($port, '/z0', "Sec-WebSocket-Extensions: permessage-deflate; bogus, permessage-deflate; client_max_window_bits=16, permessage-deflate; client_max_window_bits=12\r\n");
    echo 'ext: ', $r->ext(), "\n";
    fclose($r->c);
    $r = Raw::open($port, '/z0', "Sec-WebSocket-Extensions: x-other, permessage-deflate; server_no_context_takeover; server_no_context_takeover\r\n");
    echo 'ext: [', $r->ext(), "]\n";
    fclose($r->c);

    // 5. An inflation bomb: ~63 KB on the wire, 10 MB inflated, maxMessageSize 100000.
    $r = Raw::open($port, '/small', $offer);
    $r->send(2, zbomb(), true, 0x40);
    echo 'bomb ', $r->frame(), ' ', $r->frame(), "\n";
    fclose($r->c);

    // 6. A corrupt compressed payload.
    $r = Raw::open($port, '/z', $offer);
    $r->send(1, "\xff\xff\xff", true, 0x40);
    echo 'corrupt ', $r->frame(), ' ', $r->frame(), "\n";
    fclose($r->c);

    // 7. RSV1 on a continuation; RSV1 when nothing was negotiated.
    $r = Raw::open($port, '/z', $offer);
    $r->send(1, "\xf2\x48\xcd", false, 0x40);
    $r->send(0, "\xc9\xc9\x07\x00", true, 0x40);
    echo 'rsv1-cont ', $r->frame(), ' ', $r->frame(), "\n";
    fclose($r->c);
    $r = Raw::open($port, '/plain', $offer);
    echo 'plain ext: [', $r->ext(), "]\n";
    $r->send(1, "\xf2\x48\xcd\xc9\xc9\x07\x00", true, 0x40);
    echo 'rsv1-unnegotiated ', $r->frame(), ' ', $r->frame(), "\n";
    fclose($r->c);

    // 8. Our client offering compression to a server without it.
    $c = WS\connect('ws://127.0.0.1:' . $port . '/plain', (new WS\Options())->compression(true));
    $c->send($msg);
    echo $c->receive()->data === 'echo:' . $msg ? 'plain ok' : 'plain BAD', "\n";
    $c->close();

    $server->stop();
});
