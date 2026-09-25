<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// Writes from several tasks are serialized per frame: three tasks in the
// session's scope each send 200 frames of 3000 bytes while the session sits
// in receive(); every frame the client reads parses whole, and each task's
// frames arrive in the order it sent them.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

/** A raw WebSocket client: masks what it sends, reads frames unmasked. */
final class Raw
{
    public string $buf = '';
    public string $head = '';
    public string $payload = '';

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
        $this->payload = $p->payload;
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
                \Async\TaskGroup::run(function (\Async\TaskGroup $g) use ($ws): void {
                    for ($i = 0; $i < 3; $i++) {
                        $g->spawn(function () use ($ws, $i): void {
                            for ($n = 0; $n < 200; $n++) {
                                $head = 't' . $i . '-' . $n . ':';
                                $ws->send($head . str_repeat('x', 3000 - strlen($head)));
                            }
                        });
                    }
                    foreach ($ws as $m) {
                    }
                });
            });
        });
    });

    $r = Raw::open($port);
    $count = [0, 0, 0];
    $bad = 0;
    for ($k = 0; $k < 600; $k++) {
        $f = $r->frame();
        $p = $r->payload;
        if ($f !== '1:3000B' || $p[0] !== 't') { $bad++; continue; }
        $i = (int)substr($p, 1, 1);
        $want = 't' . $i . '-' . $count[$i] . ':';
        if (!str_starts_with($p, $want) || strspn($p, 'x', strlen($want)) !== 3000 - strlen($want)) { $bad++; continue; }
        $count[$i]++;
    }
    echo 't0=', $count[0], ' t1=', $count[1], ' t2=', $count[2], ' bad=', $bad, "\n";
    $r->send(8, "\x03\xe8");
    echo $r->frame(), "\n";
    echo $r->frame(), "\n";
    fclose($r->c);
    $server->stop();
});
