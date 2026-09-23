<?php
// MANTICORE-ONLY (php has no Http\WebSocket); expected output written by hand.
// Every way a connection ends: a session that returns while open closes with
// 1000; one that throws closes with 1011 and counts as a server error; a
// close() from another task while the session reads is bounded by
// closeTimeout against a silent peer (1006, not a ping interval later), and
// one from another task while the session is BETWEEN receives never reads in
// the closer (the session's own receive() sees the end); the owner's close()
// with no reader waits for the peer's answer (1000 echoed); stop() over idle
// sessions that do not read returns at once and serve() ends without a
// closeTimeout per session; stop() never parks behind a writer stuck on a
// full send buffer; a client Connection closes its fd once the peer's Close
// ends it. Server-side values are stashed and printed
// by the client task, so the order is fixed.

use function Async\async;
use function Async\spawn;
use Http\WebSocket as WS;

final class Seen { public static string $s = ''; }
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

function session(\Http\Request $req): \Http\Response
{
    $o = (new WS\Options())->pingInterval(3.0)->closeTimeout(0.3);
    return WS\upgrade($req, function (WS\Connection $ws) use ($req): void {
        $path = $req->path;
        if ($path === '/return') {
            return;
        }
        if ($path === '/throw') {
            throw new \RuntimeException('boom');
        }
        if ($path === '/other') {
            spawn(function () use ($ws): void {
                \Async\delay(0.05);
                $ws->close();
            });
            foreach ($ws as $m) {
            }
            Seen::$s = 'other code=' . $ws->closeCode();
            return;
        }
        if ($path === '/gap') {
            // A broadcaster kicks the client while the session is BETWEEN
            // receives: the kicker must not take over the read.
            spawn(function () use ($ws): void {
                \Async\delay(0.05);
                $t0 = microtime(true);
                $ws->close();
                Seen::$s = 'gap: closer ' . (microtime(true) - $t0 < 0.05 ? 'returned at once' : 'blocked');
            });
            \Async\delay(0.1);
            try {
                $m = $ws->receive();
                Seen::$s .= ', receive ' . ($m === null ? 'null' : 'msg') . ' code=' . $ws->closeCode();
            } catch (\LogicException $e) {
                Seen::$s .= ', receive threw ' . $e->getMessage();
            }
            return;
        }
        if ($path === '/push') {
            // A writer parked on a full send buffer (the client never reads)
            // holds the write lock; stop() must not park behind it.
            spawn(function () use ($ws): void {
                try {
                    $ws->sendBinary(str_repeat('x', 32 << 20));
                    Seen::$s = 'push: sent';
                } catch (WS\ConnectionClosedException $e) {
                    Seen::$s = 'push: writer ended';
                }
            });
            foreach ($ws as $m) {
            }
            return;
        }
        if ($path === '/noreader') {
            $ws->close(1000, 'done');
            Seen::$s = 'noreader code=' . $ws->closeCode() . ' ' . $ws->closeReason();
            return;
        }
        if (str_starts_with($path, '/idle')) {
            // Push-only: does not read until after stop(). /idle1 wakes before
            // the deadline and parks in receive() (the deadline shuts the
            // socket under it); /idle4 sleeps past it (the deadline ends the
            // connection with no reader).
            \Async\delay((int)substr($path, 5) / 10);
            $m = $ws->receive();
            Seen::$s .= ($m === null ? 'null' : 'msg') . ':' . $ws->closeCode() . ' ';
            return;
        }
        foreach ($ws as $m) {
            $ws->send($m->data);
            $ws->close(1000, 'bye');
        }
    }, $o);
}

async(function () {
    $port = 0;
    $server = \Http\Server::onListener(listen($port))->acceptWait(0.02);
    $t = spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response { return session($req); });
    });

    $r = Raw::open($port, '/return');
    echo 'return: ', $r->frame(), "\n";
    $r->send(8, "\x03\xe8");
    echo 'return: ', $r->frame(), "\n";
    fclose($r->c);

    $before = $server->stats()['errors'];
    $r = Raw::open($port, '/throw');
    echo 'throw: ', $r->frame(), "\n";
    $r->send(8, "\x03\xf3");
    echo 'throw: ', $r->frame(), "\n";
    fclose($r->c);
    \Async\delay(0.05);
    echo 'throw: errors +', $server->stats()['errors'] - $before, "\n";

    $r = Raw::open($port, '/other');
    $t0 = microtime(true);
    echo 'other: ', $r->frame(), "\n";
    echo 'other: ', $r->frame(), "\n";
    $dt = microtime(true) - $t0;
    echo 'other: ', $dt > 0.2 && $dt < 2.0 ? 'EOF within closeTimeout' : 'EOF after ' . $dt, "\n";
    fclose($r->c);
    \Async\delay(0.05);
    echo Seen::$s, "\n";

    $r = Raw::open($port, '/gap');
    echo 'gap: ', $r->frame(), "\n";
    echo 'gap: ', $r->frame(), "\n";
    fclose($r->c);
    \Async\delay(0.05);
    echo Seen::$s, "\n";

    $r = Raw::open($port, '/noreader');
    echo 'noreader: ', $r->frame(), "\n";
    $r->send(8, "\x03\xe8ok");
    echo 'noreader: ', $r->frame(), "\n";
    fclose($r->c);
    \Async\delay(0.05);
    echo Seen::$s, "\n";

    $r = Raw::open($port, '/echo');
    $c = new WS\Connection($r->c, new \Buffer\ByteBuffer(), true, new WS\Options());
    $c->send('hi');
    $m = $c->receive();
    echo 'client: ', $m === null ? 'null' : $m->data, "\n";
    $m = $c->receive();
    echo 'client: ', $m === null ? 'null' : 'msg', ' code=', $c->closeCode(), ' ', $c->closeReason(), "\n";
    echo 'client: fd ', is_resource($r->c) ? 'open' : 'closed', "\n";

    $server->stop();
    $t->await();

    Seen::$s = '';
    $server = \Http\Server::onListener(listen($port))->acceptWait(0.02);
    $t = spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response { return session($req); });
    });
    // Six idle sessions: a per-session closeTimeout would be 1.8 s.
    $rs = [];
    foreach (['/idle1', '/idle4', '/idle4', '/idle4', '/idle4', '/idle4'] as $p) {
        $rs[] = Raw::open($port, $p);
    }
    \Async\delay(0.05);
    $t0 = microtime(true);
    $server->stop();
    $dt = microtime(true) - $t0;
    echo 'stop: ', $dt < 0.25 ? 'returned at once' : 'took ' . $dt, "\n";
    $t->await();
    $dt = microtime(true) - $t0;
    echo 'stop: ', $dt > 0.2 && $dt < 1.5 ? 'serve ended without a closeTimeout per session' : 'serve ended after ' . $dt, "\n";
    foreach ($rs as $r) {
        echo 'stop: ', $r->frame(), ' ', $r->frame(), "\n";
        fclose($r->c);
    }
    echo 'stop: ', Seen::$s, "\n";

    Seen::$s = '';
    $server = \Http\Server::onListener(listen($port))->acceptWait(0.02);
    $t = spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response { return session($req); });
    });
    $r = Raw::open($port, '/push');
    \Async\delay(0.2);
    $t0 = microtime(true);
    $server->stop();
    $dt = microtime(true) - $t0;
    echo 'push: stop ', $dt < 0.25 ? 'returned at once' : 'took ' . $dt, "\n";
    $t->await();
    $n = 0;
    while (true) {
        $chunk = fread($r->c, 1 << 20);
        if ($chunk === '' || $chunk === false) { break; }
        $n += strlen($chunk);
    }
    fclose($r->c);
    echo 'push: ', $n < (32 << 20) ? 'partial message then EOF' : 'got it all (' . $n . ')', "\n";
    echo Seen::$s, "\n";
});
