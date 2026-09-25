<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// One connection per protocol violation: the server answers each with the
// Close code RFC 6455 names (1002 protocol, 1007 bad UTF-8, 1009 too big)
// and drops the connection. maxMessageSize(1000) caps frames at 1000 too.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

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

function probe(int $port, string $label, \Closure $send): void
{
    $r = Raw::open($port);
    $send($r);
    echo $label, ' ', $r->frame(), ' ', $r->frame(), "\n";
    fclose($r->c);
}

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            return WS\upgrade($req, function (WS\Connection $ws): void {
                foreach ($ws as $m) {
                }
            }, (new WS\Options())->maxMessageSize(1000));
        });
    });

    probe($port, 'unmasked', function (Raw $r): void { fwrite($r->c, "\x81\x02hi"); });
    probe($port, 'opcode3', function (Raw $r): void { $r->send(3, 'x'); });
    probe($port, 'lone-cont', function (Raw $r): void { $r->send(0, 'x'); });
    probe($port, 'text-in-text', function (Raw $r): void { $r->send(1, 'a', false); $r->send(1, 'b'); });
    probe($port, 'bad-utf8', function (Raw $r): void { $r->send(1, "\xc0\xaf"); });
    probe($port, 'message-too-big', function (Raw $r): void {
        $r->send(1, str_repeat('a', 600), false);
        $r->send(0, str_repeat('a', 600));
    });
    probe($port, 'frame-too-big', function (Raw $r): void { $r->send(2, str_repeat('a', 2000)); });
    probe($port, 'close-1-byte', function (Raw $r): void { $r->send(8, "\x03"); });
    probe($port, 'close-1005', function (Raw $r): void { $r->send(8, "\x03\xed"); });
    probe($port, 'close-999', function (Raw $r): void { $r->send(8, "\x03\xe7"); });
    probe($port, 'close-bad-reason', function (Raw $r): void { $r->send(8, "\x03\xe8\xc0\xaf"); });
    probe($port, 'ping-rsv1', function (Raw $r): void { $r->send(9, 'p', true, 0x40); });
    $server->stop();
});
