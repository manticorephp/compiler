<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// The server side against a hand-rolled client: handshake, echo of every
// length form, a frame sent in the same segment as the upgrade request,
// ping → pong, and the close handshake echoing the code. The server's view
// of the close is stashed and printed by the client, so the order is fixed.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

final class Seen { public static string $close = ''; }

/** A raw WebSocket client: masks what it sends, reads frames unmasked. */
final class Raw
{
    public string $buf = '';
    public string $head = '';

    public function __construct(public \Resource $c) {}

    public static function open(int $port, string $path = '/ws', string $extra = '', string $firstBytes = ''): Raw
    {
        $c = stream_socket_client('tcp://127.0.0.1:' . $port);
        fwrite($c, "GET $path HTTP/1.1\r\nHost: t\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n" . $extra . "\r\n" . $firstBytes);
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

    public function send(int $op, string $payload, bool $fin = true, int $rsvBits = 0): void
    {
        $f = \Http\WebSocket\encodeFrame($op, $payload, $fin, false, "\x11\x22\x33\x44");
        fwrite($this->c, chr(ord($f[0]) | $rsvBits) . substr($f, 1));
    }

    /** Next frame as "op:payload" (close → "8:<code>:<reason>"), or "EOF". */
    public function frame(): string
    {
        $b = new \Buffer\ByteBuffer();
        $b->append($this->buf);
        $p = new \Http\WebSocket\FrameParser($b, false, 1 << 26);
        while (($r = $p->parse()) === \Http\WebSocket\FrameParser::NEED) {
            $chunk = fread($this->c, 65536);
            if ($chunk === '' || $chunk === false) { return 'EOF'; }
            $b->append($chunk);
        }
        $this->buf = $b->view();
        if ($r !== \Http\WebSocket\FrameParser::FRAME) { return 'bad' . $r; }
        if ($p->opcode === 8) {
            $code = strlen($p->payload) >= 2 ? (ord($p->payload[0]) << 8) | ord($p->payload[1]) : 0;
            return '8:' . $code . ':' . substr($p->payload, 2);
        }
        return $p->opcode . ':' . (strlen($p->payload) > 32 ? strlen($p->payload) . 'B' : $p->payload);
    }
}

function listen(int &$port): \Resource
{
    $l = stream_socket_server('tcp://127.0.0.1:0');
    stream_set_blocking($l, false);
    $n = stream_socket_get_name($l, false);
    $port = (int)substr($n, strrpos($n, ':') + 1);
    return $l;
}

$port = 0;
$l = listen($port);
$server = \Http\Server::onListener($l)->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            return WS\upgrade($req, function (WS\Connection $ws): void {
                foreach ($ws as $m) {
                    $m->binary ? $ws->sendBinary($m->data) : $ws->send('echo:' . $m->data);
                }
                Seen::$close = 'server saw close ' . $ws->closeCode() . ' ' . $ws->closeReason();
            });
        });
    });

    $r = Raw::open($port, '/ws', '', \Http\WebSocket\encodeFrame(1, 'early', true, false, "\x01\x02\x03\x04"));
    echo strtok($r->head, "\r\n"), "\n";
    echo stripos($r->head, "\r\nSec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n") !== false ? "accept ok\n" : "accept BAD\n";
    echo $r->frame(), "\n";
    $r->send(1, 'hi');
    echo $r->frame(), "\n";
    foreach ([0, 125, 126, 65535, 65536, 1048576] as $n) {
        $r->send(2, str_repeat('b', $n));
        echo $n, ' -> ', $r->frame(), "\n";
    }
    $r->send(9, 'png');
    echo $r->frame(), "\n";
    $r->send(8, "\x03\xe8bye");
    echo $r->frame(), "\n";
    echo $r->frame(), "\n";
    fclose($r->c);
    \Async\delay(0.05);
    echo Seen::$close, "\n";
    $server->stop();
});
