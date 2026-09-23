<?php
// Http\WebSocket — RFC 6455 over Http\Server (upgrade) and as a client.
// DEMAND-GATED on the `WebSocket` qualifier; rides Http\ (header parsing, the
// takeover hook) and Buffer\ByteBuffer. Superset feature: no Zend oracle — see
// docs/websocket.md and tests/aot/cases/ws_*.

namespace Http\WebSocket {

final class Opcode
{
    public const CONT = 0;
    public const TEXT = 1;
    public const BINARY = 2;
    public const CLOSE = 8;
    public const PING = 9;
    public const PONG = 10;
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
}

/** Sec-WebSocket-Accept for a client's Sec-WebSocket-Key (RFC 6455 §4.2.2). */
function acceptKey(string $key): string
{
    return \base64_encode(\sha1($key . Opcode::GUID, true));
}

/** XOR $data with the 4-byte $mask repeated — one string op, never a byte loop. */
function applyMask(string $data, string $mask): string
{
    $n = \strlen($data);
    if ($n === 0) {
        return '';
    }
    return $data ^ \substr(\str_repeat($mask, ($n >> 2) + 1), 0, $n);
}

/** One frame. `$mask === ''` sends it unmasked (server → client). */
function encodeFrame(int $opcode, string $payload, bool $fin, bool $rsv1, string $mask): string
{
    $n = \strlen($payload);
    $b0 = ($fin ? 0x80 : 0) | ($rsv1 ? 0x40 : 0) | $opcode;
    $mb = $mask === '' ? 0 : 0x80;
    if ($n < 126) {
        $head = \chr($b0) . \chr($mb | $n);
    } elseif ($n < 65536) {
        $head = \chr($b0) . \chr($mb | 126) . \chr($n >> 8) . \chr($n & 0xFF);
    } else {
        $head = \chr($b0) . \chr($mb | 127);
        for ($s = 56; $s >= 0; $s -= 8) {
            $head .= \chr(($n >> $s) & 0xFF);
        }
    }
    if ($mask === '') {
        return $head . $payload;
    }
    return $head . $mask . applyMask($payload, $mask);
}

/**
 * A close code a peer may put on the wire (§7.4): the defined 1000–1003 and
 * 1007–1014, and the registered/private 3000–4999. 1004–1006 and 1015 are
 * reserved for local reporting and never sent.
 */
function closeCodeOk(int $code): bool
{
    return ($code >= 1000 && $code <= 1003) || ($code >= 1007 && $code <= 1014)
        || ($code >= 3000 && $code <= 4999);
}

function closeCodeSendable(int $code): bool
{
    return closeCodeOk($code);
}

/** PCRE rejects invalid UTF-8 under /u, including overlongs and surrogates. */
function utf8Ok(string $s): bool
{
    return $s === '' || \preg_match('//u', $s) === 1;
}

/**
 * Frames out of a ByteBuffer, incrementally. parse() answers NEED (not enough
 * bytes yet), FRAME (the public fields hold it, bytes consumed), or the close
 * code for a protocol error the header alone proves (1002, 1009). The payload
 * length is checked BEFORE the payload is waited for, so a peer cannot make us
 * buffer an oversized frame.
 */
final class FrameParser
{
    public const NEED = 0;
    public const FRAME = 1;

    public bool $fin = false;
    public bool $rsv1 = false;
    public int $opcode = 0;
    public string $payload = '';

    public function __construct(
        private \Buffer\ByteBuffer $buf,
        private bool $expectMasked,
        private int $maxFrame,
        public bool $allowRsv1 = false,
    ) {}

    public function parse(): int
    {
        $b = $this->buf;
        $have = $b->length();
        if ($have < 2) {
            return self::NEED;
        }
        $b0 = $b->byteAt(0);
        $b1 = $b->byteAt(1);
        $fin = ($b0 & 0x80) !== 0;
        $rsv1 = ($b0 & 0x40) !== 0;
        $op = $b0 & 0x0F;
        if (($b0 & 0x30) !== 0 || ($rsv1 && !$this->allowRsv1)) {
            return 1002;
        }
        if (($op > 2 && $op < 8) || $op > 10) {
            return 1002;
        }
        $masked = ($b1 & 0x80) !== 0;
        if ($masked !== $this->expectMasked) {
            return 1002;
        }
        $len = $b1 & 0x7F;
        if ($op >= 8 && (!$fin || $len > 125 || $rsv1)) {
            return 1002;
        }
        $pos = 2;
        if ($len === 126) {
            if ($have < 4) {
                return self::NEED;
            }
            $len = ($b->byteAt(2) << 8) | $b->byteAt(3);
            $pos = 4;
        } elseif ($len === 127) {
            if ($have < 10) {
                return self::NEED;
            }
            if (($b->byteAt(2) & 0x80) !== 0) {
                return 1002;
            }
            $len = 0;
            for ($i = 2; $i < 10; $i++) {
                $len = ($len << 8) | $b->byteAt($i);
            }
            $pos = 10;
        }
        if ($len > $this->maxFrame) {
            return 1009;
        }
        $mlen = $masked ? 4 : 0;
        if ($have < $pos + $mlen + $len) {
            return self::NEED;
        }
        $b->skip($pos);
        $mask = $masked ? $b->read(4) : '';
        $p = $len > 0 ? $b->read($len) : '';
        $this->fin = $fin;
        $this->rsv1 = $rsv1;
        $this->opcode = $op;
        $this->payload = $masked ? applyMask($p, $mask) : $p;
        return self::FRAME;
    }
}


final class Message
{
    public function __construct(
        public readonly string $data,
        public readonly bool $binary,
    ) {}
}

final class ConnectionClosedException extends \RuntimeException {}

final class HandshakeException extends \RuntimeException {}

final class Options
{
    public int $maxMessageSize = 16777216;
    public int $maxFrameSize = 16777216;
    public float $pingInterval = 30.0;
    public float $pongTimeout = 10.0;
    public float $closeTimeout = 5.0;
    public float $connectTimeout = 10.0;
    /** @var array<string,mixed> */
    public array<string, mixed> $sslContext = [];
    /** @var array<int,string> */
    public array<int, string> $protocols = [];
    /** @var array<int,string> */
    public array<int, string> $allowedOrigins = [];
    public bool $compression = false;
    public int $compressionMinBytes = 256;

    public function maxMessageSize(int $n): Options { $this->maxMessageSize = $n; if ($this->maxFrameSize > $n) { $this->maxFrameSize = $n; } return $this; }
    public function maxFrameSize(int $n): Options { $this->maxFrameSize = $n; return $this; }
    public function pingInterval(float $s): Options { $this->pingInterval = $s; return $this; }
    public function pongTimeout(float $s): Options { $this->pongTimeout = $s; return $this; }
    public function closeTimeout(float $s): Options { $this->closeTimeout = $s; return $this; }
    public function connectTimeout(float $s): Options { $this->connectTimeout = $s; return $this; }
    /** @param array<string,mixed> $ssl */
    public function sslContext(array<string, mixed> $ssl): Options { $this->sslContext = $ssl; return $this; }
    /** @param array<int,string> $p */
    public function protocols(array<int, string> $p): Options { $this->protocols = $p; return $this; }
    /** @param array<int,string> $o */
    public function allowedOrigins(array<int, string> $o): Options { $this->allowedOrigins = $o; return $this; }
    public function compression(bool $on): Options { $this->compression = $on; return $this; }
    public function compressionMinBytes(int $n): Options { $this->compressionMinBytes = $n; return $this; }
}

/** Seconds as stream_set_timeout's (int, µs) pair. */
function setTimeout(\Resource $s, float $seconds): void
{
    $whole = (int)$seconds;
    \stream_set_timeout($s, $whole, (int)(($seconds - $whole) * 1000000.0));
}

function timedOut(\Resource $s): bool
{
    $meta = \stream_get_meta_data($s);
    return isset($meta['timed_out']) && $meta['timed_out'];
}

/**
 * One WebSocket, either end. Reads happen in whichever task calls receive()
 * (one at a time); writes may come from any task and are serialized, so a
 * broadcaster never interleaves two frames. The connection is kept alive only
 * while someone reads it: the read timeout IS the ping timer.
 *
 * A Close started outside the reading task never reads: it sends the frame
 * and arms a closeTimeout deadline in the connection's scope. A parked reader
 * sees the peer's answer; if none comes in time, the deadline shuts the socket
 * down, which wakes that reader with EOF (or, with no reader, ends it there).
 */
final class Connection implements \IteratorAggregate
{
    private FrameParser $parser;
    private ?\Async\Mutex $wlock = null;
    private ?\Async\TaskGroup $scope = null;
    private ?\Async\Task $deadline = null;
    /** The task that built this connection: the server session, or the client's connect() caller. */
    private ?\Async\Task $owner = null;
    private bool $reading = false;
    private bool $sentClose = false;
    private bool $gotClose = false;
    private bool $closed = false;
    private bool $fdClosed = false;
    private bool $awaitingPong = false;
    private int $peerCode = 1005;
    private string $peerReason = '';
    private string $maskPool = '';
    private int $maskPos = 0;

    public function __construct(
        private \Resource $sock,
        private \Buffer\ByteBuffer $buf,
        private bool $client,
        private Options $o,
        private string $protocol = '',
        private ?\Http\Request $request = null,
        private string $remoteAddr = '',
    ) {
        $this->parser = new FrameParser($buf, !$client, $o->maxFrameSize);
        $scope = \Async\Context::currentScope();
        if ($scope !== null) {
            $this->scope = $scope;
            $this->owner = \Async\Scheduler::instance()->current();
            $this->wlock = new \Async\Mutex();
        }
    }

    public function protocol(): string { return $this->protocol; }
    public function request(): ?\Http\Request { return $this->request; }
    public function remoteAddr(): string { return $this->remoteAddr; }
    public function isOpen(): bool { return !$this->closed && !$this->sentClose; }
    /** The peer's Close code (1005 when it carried none); 1006 when no Close came. */
    public function closeCode(): int { return $this->gotClose ? $this->peerCode : 1006; }
    public function closeReason(): string { return $this->gotClose ? $this->peerReason : ''; }

    /** Messages until the connection closes. */
    public function getIterator(): \Generator
    {
        while (($m = $this->receive()) !== null) {
            yield $m;
        }
    }

    /** The next message, or null once closed ({@see closeCode} says why). */
    public function receive(): ?Message
    {
        if ($this->reading) {
            throw new \LogicException('Http\\WebSocket\\Connection::receive() is already running in another task');
        }
        if ($this->closed) {
            return null;
        }
        $this->reading = true;
        try {
            return $this->readMessage();
        } finally {
            $this->reading = false;
            $this->releaseFd();
        }
    }

    public function send(string $data): void
    {
        $this->sendData(Opcode::TEXT, $data);
    }

    public function sendBinary(string $data): void
    {
        $this->sendData(Opcode::BINARY, $data);
    }

    public function ping(string $payload = ''): void
    {
        if (\strlen($payload) > 125) {
            throw new \ValueError('Http\\WebSocket\\Connection::ping(): Argument #1 ($payload) must be at most 125 bytes');
        }
        if (!$this->writeFrame(Opcode::PING, $payload, false)) {
            throw new ConnectionClosedException('WebSocket connection is closed');
        }
    }

    /**
     * Start the closing handshake. From any task but the owner (a broadcaster
     * kicking a client), or while a read is in flight, it sends the Close and
     * arms the closeTimeout deadline — the owner's next or current receive()
     * sees the answer (or the deadline's EOF) and returns null. The owner with
     * no read in flight waits here, up to closeTimeout, for the peer's Close,
     * discarding data frames.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!closeCodeSendable($code)) {
            throw new \ValueError('Http\\WebSocket\\Connection::close(): Argument #1 ($code) must be 1000-1003, 1007-1014 or 3000-4999');
        }
        if (\strlen($reason) > 123) {
            throw new \ValueError('Http\\WebSocket\\Connection::close(): Argument #2 ($reason) must be at most 123 bytes');
        }
        if ($this->closed || $this->sentClose) {
            return;
        }
        if (!$this->sendClose($code, $reason)) {
            return;
        }
        if ($this->reading || !$this->isOwner()) {
            $this->armDeadline();
            return;
        }
        $this->awaitPeerClose();
    }

    private function isOwner(): bool
    {
        $o = $this->owner;
        return $o === null || \Async\Scheduler::instance()->current() === $o;
    }

    /** @internal Server side: run the upgrade's session to its end. */
    public function runSession(\Closure $session, ?\Http\Server $server): void
    {
        $stopId = 0;
        if ($server !== null) {
            $stopId = $server->onStop(function (): void {
                if ($this->closed || $this->sentClose) {
                    return;
                }
                // Never park stop() behind a writer stuck on a full send
                // buffer: skip the Close, the deadline still ends it (1006).
                $l = $this->wlock;
                if ($l === null || !$l->isLocked()) {
                    if (!$this->sendClose(1001, 'server shutdown')) {
                        return;
                    }
                }
                $this->armDeadline();
            });
        }
        try {
            $session($this);
            if ($this->isOpen()) {
                $this->close(1000);
            } elseif (!$this->closed && !$this->reading) {
                $this->awaitPeerClose();
            }
        } catch (\Async\CancelledException $e) {
            if ($this->isOpen()) {
                \Async\shield(function (): void { $this->sendClose(1001, 'server shutdown'); });
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->isOpen()) {
                try {
                    $this->close(1011);
                } catch (\Throwable $e2) {
                }
            }
            throw $e;
        } finally {
            if ($server !== null) {
                $server->offStop($stopId);
            }
            // The server closes the socket once we return: nothing of ours
            // may touch it after that.
            $this->finish();
        }
    }

    private function sendData(int $op, string $data): void
    {
        if (!$this->writeFrame($op, $data, false)) {
            throw new ConnectionClosedException('WebSocket connection is closed');
        }
    }

    /** Read (as the reader) until the peer's Close answers ours, or closeTimeout. */
    private function awaitPeerClose(): void
    {
        $this->reading = true;
        try {
            while (!$this->closed && $this->readMessage() !== null) {
            }
        } finally {
            $this->reading = false;
            $this->releaseFd();
        }
    }

    /**
     * Bound a Close we sent by closeTimeout without reading here: a task in
     * the connection's scope shuts the socket down if the connection is still
     * not over by then. finish() cancels it.
     */
    private function armDeadline(): void
    {
        $scope = $this->scope;
        if ($this->closed || $this->deadline !== null || $scope === null) {
            return;
        }
        $wait = $this->o->closeTimeout;
        $this->deadline = $scope->spawn(function () use ($wait): void {
            \Async\delay($wait);
            $this->deadline = null;
            if ($this->closed) {
                return;
            }
            if ($this->reading) {
                \stream_socket_shutdown($this->sock, \STREAM_SHUT_RDWR);
            } else {
                $this->finish();
            }
        });
    }

    private function readMessage(): ?Message
    {
        $op = -1;
        $data = '';
        $compressed = false;
        while (true) {
            $r = $this->parser->parse();
            if ($r === FrameParser::NEED) {
                if (!$this->fill()) {
                    return null;
                }
                continue;
            }
            if ($r !== FrameParser::FRAME) {
                $this->fail($r);
                return null;
            }
            $fop = $this->parser->opcode;
            $p = $this->parser->payload;
            if ($fop === Opcode::PING) {
                $this->writeFrame(Opcode::PONG, $p, false);
                continue;
            }
            if ($fop === Opcode::PONG) {
                continue;
            }
            if ($fop === Opcode::CLOSE) {
                $this->peerClosed($p);
                return null;
            }
            if ($fop === Opcode::CONT) {
                if ($op < 0 || $this->parser->rsv1) {
                    $this->fail(1002);
                    return null;
                }
            } else {
                if ($op >= 0) {
                    $this->fail(1002);
                    return null;
                }
                $op = $fop;
                $compressed = $this->parser->rsv1;
            }
            if (\strlen($data) + \strlen($p) > $this->o->maxMessageSize) {
                $this->fail(1009);
                return null;
            }
            $data .= $p;
            if (!$this->parser->fin) {
                continue;
            }
            if ($op === Opcode::TEXT && !utf8Ok($data)) {
                $this->fail(1007);
                return null;
            }
            return new Message($data, $op === Opcode::BINARY);
        }
    }

    /**
     * One read. False when the connection is over (EOF, error, the ping went
     * unanswered, or our Close went unanswered for closeTimeout). With the
     * ping off, a read timeout only re-arms the wait.
     */
    private function fill(): bool
    {
        $ping = $this->o->pingInterval;
        if ($this->sentClose) {
            $wait = $this->o->closeTimeout;
        } elseif ($ping <= 0.0) {
            $wait = 86400.0;
        } else {
            $wait = $this->awaitingPong ? $this->o->pongTimeout : $ping;
        }
        setTimeout($this->sock, $wait);
        $chunk = \fread($this->sock, 65536);
        if ($chunk === '' || $chunk === false) {
            if (!$this->sentClose && !$this->closed && timedOut($this->sock)) {
                if ($ping <= 0.0) {
                    return true;
                }
                if (!$this->awaitingPong) {
                    $this->awaitingPong = true;
                    return $this->writeFrame(Opcode::PING, '', false);
                }
            }
            $this->finish();
            return false;
        }
        $this->awaitingPong = false;
        $this->buf->append($chunk);
        return true;
    }

    private function peerClosed(string $p): void
    {
        $n = \strlen($p);
        if ($n === 1) {
            $this->fail(1002);
            return;
        }
        $code = 1005;
        $reason = '';
        if ($n >= 2) {
            $code = (\ord($p[0]) << 8) | \ord($p[1]);
            $reason = \substr($p, 2);
            if (!closeCodeOk($code)) {
                $this->fail(1002);
                return;
            }
            if (!utf8Ok($reason)) {
                $this->fail(1007);
                return;
            }
        }
        $this->gotClose = true;
        $this->peerCode = $code;
        $this->peerReason = $reason;
        if (!$this->sentClose) {
            $this->sendClose($code === 1005 ? 1000 : $code, '');
        }
        $this->finish();
    }

    /** A protocol error: tell the peer why, then drop the connection. */
    private function fail(int $code): void
    {
        if (!$this->sentClose) {
            $this->sendClose($code, '');
        }
        $this->finish();
    }

    /**
     * The connection is over. Shutdown, not fclose: another task may be parked
     * on this fd; a shutdown wakes it with EOF where a close would leave it
     * waiting on a descriptor number the kernel may already have reused. The
     * server closes its end when the session returns; the client's is closed
     * by whoever leaves the socket last ({@see releaseFd}).
     */
    private function finish(): void
    {
        $d = $this->deadline;
        $this->deadline = null;
        if ($d !== null) {
            $d->cancel();
        }
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        \stream_socket_shutdown($this->sock, \STREAM_SHUT_RDWR);
        $this->releaseFd();
    }

    /** Client side: close the fd once the connection is over and no task is on it. */
    private function releaseFd(): void
    {
        if (!$this->client || !$this->closed || $this->fdClosed || $this->reading) {
            return;
        }
        $l = $this->wlock;
        if ($l !== null && $l->isLocked()) {
            return;
        }
        $this->fdClosed = true;
        \fclose($this->sock);
    }

    /** True when the Close reached the wire; a failed write ends the connection instead. */
    private function sendClose(int $code, string $reason): bool
    {
        if (!$this->writeRaw(encodeFrame(Opcode::CLOSE, \chr($code >> 8) . \chr($code & 0xFF) . $reason, true, false, $this->nextMask()))) {
            return false;
        }
        $this->sentClose = true;
        return true;
    }

    /** A frame, unless we already sent Close (after which only the answer may be read). */
    private function writeFrame(int $op, string $payload, bool $rsv1): bool
    {
        if ($this->closed || $this->sentClose) {
            return false;
        }
        return $this->writeRaw(encodeFrame($op, $payload, true, $rsv1, $this->nextMask()));
    }

    private function writeRaw(string $bytes): bool
    {
        if ($this->closed) {
            return false;
        }
        $l = $this->wlock;
        if ($l !== null) {
            $l->lock();
        }
        $ok = false;
        try {
            if (!$this->closed) {
                $ok = \fwrite($this->sock, $bytes) === \strlen($bytes);
            }
        } finally {
            if ($l !== null) {
                $l->unlock();
            }
        }
        if (!$ok) {
            $this->finish();
            return false;
        }
        $this->releaseFd();
        return true;
    }

    /** '' on the server; four CSPRNG bytes per frame on the client, drawn from a 4 KiB pool. */
    private function nextMask(): string
    {
        if (!$this->client) {
            return '';
        }
        if ($this->maskPos + 4 > \strlen($this->maskPool)) {
            $this->maskPool = \random_bytes(4096);
            $this->maskPos = 0;
        }
        $m = \substr($this->maskPool, $this->maskPos, 4);
        $this->maskPos = $this->maskPos + 4;
        return $m;
    }
}

/** Header tokens (comma-separated, case-insensitive) — `Connection: keep-alive, Upgrade`. */
function hasToken(string $value, string $token): bool
{
    foreach (\explode(',', $value) as $t) {
        if (\strcasecmp(\trim($t), $token) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Answer a WebSocket upgrade request. A request that is not one answers an
 * ordinary 400/426/403 — hand it back from the handler like any Response.
 * $session receives the Connection and runs in the connection's own fiber;
 * when it returns, an open connection is closed with 1000.
 */
function upgrade(\Http\Request $req, callable $session, ?Options $o = null): \Http\Response
{
    $o = $o ?? new Options();
    $h = $req->headers;
    $key = $h->get('Sec-WebSocket-Key');
    $raw = $key === '' ? false : \base64_decode($key, true);
    if ($req->method !== 'GET' || $req->version !== '1.1'
        || !hasToken($h->get('Upgrade'), 'websocket') || !hasToken($h->get('Connection'), 'upgrade')
        || $raw === false || \strlen($raw) !== 16) {
        return (new \Http\Response(400))->text("Bad WebSocket upgrade request\n")->close();
    }
    if (\trim($h->get('Sec-WebSocket-Version')) !== '13') {
        return (new \Http\Response(426))->header('Sec-WebSocket-Version', '13')->text("Upgrade Required\n")->close();
    }
    if (\count($o->allowedOrigins) > 0 && !\in_array($h->get('Origin'), $o->allowedOrigins, true)) {
        return (new \Http\Response(403))->text("Forbidden\n")->close();
    }
    $proto = '';
    if (\count($o->protocols) > 0) {
        foreach (\explode(',', $h->get('Sec-WebSocket-Protocol')) as $p) {
            $p = \trim($p);
            if ($p !== '' && \in_array($p, $o->protocols, true)) {
                $proto = $p;
                break;
            }
        }
    }
    $res = (new \Http\Response(101))
        ->header('Upgrade', 'websocket')
        ->header('Connection', 'Upgrade')
        ->header('Sec-WebSocket-Accept', acceptKey($key));
    if ($proto !== '') {
        $res->header('Sec-WebSocket-Protocol', $proto);
    }
    $fn = \Closure::fromCallable($session);
    return $res->takeover(function (\Resource $sock, \Buffer\ByteBuffer $buf, \Http\Server $server) use ($fn, $o, $proto, $req): void {
        $c = new Connection($sock, $buf, false, $o, $proto, $req, $req->remoteAddr);
        $c->runSession($fn, $server);
    });
}

}
