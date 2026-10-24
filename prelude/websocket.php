<?php
// Http\WebSocket — RFC 6455 over Http\Server (upgrade) and as a client.
// DEMAND-GATED on the `WebSocket` qualifier; rides Http\ (header parsing, the
// takeover hook) and Buffer\ByteBuffer. Superset feature: no Zend oracle — see
// docs/websocket.md and tests/aot/cases/ws_*.

namespace Http\WebSocket {

/** Frame opcodes and the handshake GUID of RFC 6455.
 * @internal */
final class Opcode
{
    /** Continuation frame. @internal */
    public const CONT = 0;
    /** Text frame. @internal */
    public const TEXT = 1;
    /** Binary frame. @internal */
    public const BINARY = 2;
    /** Close frame. @internal */
    public const CLOSE = 8;
    /** Ping frame. @internal */
    public const PING = 9;
    /** Pong frame. @internal */
    public const PONG = 10;
    /** Handshake GUID. @internal */
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
}

/**
 * Sec-WebSocket-Accept for a client's Sec-WebSocket-Key.
 *
 * @internal
 */
// Sec-WebSocket-Accept for a client's Sec-WebSocket-Key (RFC 6455 §4.2.2).
function acceptKey(string $key): string
{
    return \base64_encode(\sha1($key . Opcode::GUID, true));
}

/**
 * XOR a payload with a 4-byte mask.
 *
 * @internal
 */
// XOR $data with the 4-byte $mask repeated — one string op, never a byte loop.
function applyMask(string $data, string $mask): string
{
    $n = \strlen($data);
    if ($n === 0) {
        return '';
    }
    return $data ^ \substr(\str_repeat($mask, ($n >> 2) + 1), 0, $n);
}

/**
 * Encode one frame.
 *
 * @internal
 */
// One frame. `$mask === ''` sends it unmasked (server → client).
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
 * Whether a close code may appear on the wire.
 *
 * @internal
 */
// A close code a peer may put on the wire (§7.4): the defined 1000–1003 and
// 1007–1014, and the registered/private 3000–4999. 1004–1006 and 1015 are
// reserved for local reporting and never sent.
function closeCodeOk(int $code): bool
{
    return ($code >= 1000 && $code <= 1003) || ($code >= 1007 && $code <= 1014)
        || ($code >= 3000 && $code <= 4999);
}

/**
 * Whether a close code may be sent with Connection::close().
 *
 * @internal
 */
function closeCodeSendable(int $code): bool
{
    return closeCodeOk($code);
}

/**
 * Whether a string is valid UTF-8.
 *
 * @internal
 */
// PCRE rejects invalid UTF-8 under /u, including overlongs and surrogates.
function utf8Ok(string $s): bool
{
    return $s === '' || \preg_match('//u', $s) === 1;
}

/**
 * Incremental frame parser over a ByteBuffer.
 *
 * @internal
 */
// Frames out of a ByteBuffer, incrementally. parse() answers NEED (not enough
// bytes yet), FRAME (the public fields hold it, bytes consumed), or the close
// code for a protocol error the header alone proves (1002, 1009). The payload
// length is checked BEFORE the payload is waited for, so a peer cannot make us
// buffer an oversized frame.
final class FrameParser
{
    /** @internal */
    public const NEED = 0;
    /** @internal */
    public const FRAME = 1;

    /** @internal */
    public bool $fin = false;
    /** @internal */
    public bool $rsv1 = false;
    /** @internal */
    public int $opcode = 0;
    /** @internal */
    public string $payload = '';

    /** @internal */
    public function __construct(
        private \Buffer\ByteBuffer $buf,
        private bool $expectMasked,
        private int $maxFrame,
        public bool $allowRsv1 = false,
    ) {}

    /** @internal */
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


/**
 * One received message.
 *
 * `$data` is the whole message after fragments are reassembled and, when
 * permessage-deflate is on, decompressed. A text message is guaranteed to be
 * valid UTF-8 (otherwise the connection closes with 1007). `$binary` tells a
 * binary message from a text one.
 *
 * @see Connection::receive
 */
final class Message
{
    /**
     * Create a message. Received messages are built by the connection; this is
     * useful for tests and fakes.
     *
     * @param string $data  the message payload
     * @param bool $binary  true for a binary message, false for text
     */
    public function __construct(
        public readonly string $data,
        public readonly bool $binary,
    ) {}
}

/**
 * Thrown by Connection::send(), sendBinary() and ping() when the connection is
 * already closed or closing, or when the write fails.
 *
 * @see Connection
 */
final class ConnectionClosedException extends \RuntimeException {}

/**
 * Thrown by connect() when the WebSocket opening handshake does not complete.
 *
 * Causes: an unsupported URL scheme (anything but `ws://` or `wss://`), an
 * empty host, CR/LF/NUL in the URL, a failed TCP/TLS connect, a response head
 * over 16 KiB, a timeout (see Options::$connectTimeout), a non-101 status, a
 * missing or wrong `Sec-WebSocket-Accept`, or a subprotocol or extension the
 * client did not offer. The message names what disagreed.
 *
 * @see connect
 */
final class HandshakeException extends \RuntimeException {}

/**
 * Settings for a WebSocket connection, server or client side.
 *
 * Every setting is a public property with a fluent setter of the same name that
 * returns the same object, so options chain:
 *
 * ```php
 * $o = (new Options())->protocols(['chat.v2'])->pingInterval(15.0)->compression(true);
 * ```
 *
 * Durations are in seconds. An `Options` is passed to upgrade() on the server
 * and to connect() on the client; the same object may serve many connections.
 *
 * @see upgrade
 * @see connect
 */
final class Options
{
    /**
     * Largest accepted message, in bytes, after reassembly and decompression.
     * Default 16 MiB. A bigger message closes the connection with 1009.
     */
    public int $maxMessageSize = 16777216;
    /**
     * Largest accepted single frame payload, in bytes. Default 16 MiB. Checked
     * from the frame header before the payload is read; a bigger frame closes
     * the connection with 1009.
     */
    public int $maxFrameSize = 16777216;
    /**
     * Seconds of silence before a ping is sent; also the read timeout. Default
     * 30. `0` turns keepalive off.
     */
    public float $pingInterval = 30.0;
    /**
     * Seconds to wait for the answer to a keepalive ping before the connection
     * is dropped (close code 1006). Default 10.
     */
    public float $pongTimeout = 10.0;
    /**
     * Seconds to wait for the peer's Close frame after sending ours. Default 5.
     */
    public float $closeTimeout = 5.0;
    /**
     * Seconds allowed for the TCP/TLS connect and the handshake response
     * together, as one deadline (client only). Default 10.
     */
    public float $connectTimeout = 10.0;
    /**
     * TLS options for `wss://` (client only), in the shape of a stream
     * context's `ssl` options: `verify_peer`, `cafile`, and so on. Default
     * empty. SNI and the peer name are filled in from the URL's host.
     *
     * @var array<string,mixed>
     */
    public array<string, mixed> $sslContext = [];
    /**
     * Subprotocols, in order of preference. Default empty.
     *
     * Server: the first entry of the client's `Sec-WebSocket-Protocol` list that
     * is also in this array is chosen; with no match no protocol header is sent.
     * Client: all entries are offered, and a protocol the server answers with
     * must be one of them.
     *
     * @var array<int,string>
     */
    public array<int, string> $protocols = [];
    /**
     * Origins allowed to upgrade (server only). Default empty, which checks
     * nothing. When non-empty, a request whose `Origin` header is not an exact
     * member of this list (including a request with no `Origin`) gets a 403. Set it whenever the connection is
     * privileged: a browser sends cookies on a cross-site WebSocket upgrade
     * and CORS does not police it.
     *
     * @var array<int,string>
     */
    public array<int, string> $allowedOrigins = [];
    /**
     * Negotiate permessage-deflate (RFC 7692) compression. Default off; turn it
     * on at both ends.
     */
    public bool $compression = false;
    /**
     * With compression on, only messages of at least this many bytes are
     * compressed. Default 256.
     */
    public int $compressionMinBytes = 256;

    /**
     * Set {@see $maxMessageSize}. Also lowers {@see $maxFrameSize} when that is
     * larger than the new limit.
     *
     * @param int $n  limit in bytes
     * @return Options  this object
     */
    public function maxMessageSize(int $n): Options { $this->maxMessageSize = $n; if ($this->maxFrameSize > $n) { $this->maxFrameSize = $n; } return $this; }
    /**
     * Set {@see $maxFrameSize}.
     *
     * @param int $n  limit in bytes
     * @return Options  this object
     */
    public function maxFrameSize(int $n): Options { $this->maxFrameSize = $n; return $this; }
    /**
     * Set {@see $pingInterval}.
     *
     * @param float $s  seconds; 0 turns keepalive off
     * @return Options  this object
     */
    public function pingInterval(float $s): Options { $this->pingInterval = $s; return $this; }
    /**
     * Set {@see $pongTimeout}.
     *
     * @param float $s  seconds
     * @return Options  this object
     */
    public function pongTimeout(float $s): Options { $this->pongTimeout = $s; return $this; }
    /**
     * Set {@see $closeTimeout}.
     *
     * @param float $s  seconds
     * @return Options  this object
     */
    public function closeTimeout(float $s): Options { $this->closeTimeout = $s; return $this; }
    /**
     * Set {@see $connectTimeout}.
     *
     * @param float $s  seconds
     * @return Options  this object
     */
    public function connectTimeout(float $s): Options { $this->connectTimeout = $s; return $this; }
    /**
     * Set {@see $sslContext}.
     *
     * @param array<string,mixed> $ssl  `ssl` stream-context options
     * @return Options  this object
     */
    public function sslContext(array<string, mixed> $ssl): Options { $this->sslContext = $ssl; return $this; }
    /**
     * Set {@see $protocols}.
     *
     * @param array<int,string> $p  subprotocol names, most preferred first
     * @return Options  this object
     */
    public function protocols(array<int, string> $p): Options { $this->protocols = $p; return $this; }
    /**
     * Set {@see $allowedOrigins}.
     *
     * @param array<int,string> $o  allowed `Origin` values, for example `https://example.com`
     * @return Options  this object
     */
    public function allowedOrigins(array<int, string> $o): Options { $this->allowedOrigins = $o; return $this; }
    /**
     * Set {@see $compression}.
     *
     * @param bool $on  true to negotiate permessage-deflate
     * @return Options  this object
     */
    public function compression(bool $on): Options { $this->compression = $on; return $this; }
    /**
     * Set {@see $compressionMinBytes}.
     *
     * @param int $n  threshold in bytes
     * @return Options  this object
     */
    public function compressionMinBytes(int $n): Options { $this->compressionMinBytes = $n; return $this; }
}

/**
 * permessage-deflate state for one connection.
 *
 * @internal
 */
// permessage-deflate (RFC 7692) for one connection. Our deflater is limited to
// the window the peer allowed; our inflater always keeps 32 KiB, which is
// correct for any window the peer uses (8..15). An agreement that would bind
// OUR deflater to window 8 is declined, never answered with 9 (RFC 7692 lets a
// peer only lower a value; zlib, and php/zlib with it, refuses a raw 256-byte
// window): the server skips such an offer, the client fails the handshake.
final class Pmd
{
    public bool $serverNoCtx = false;
    public bool $clientNoCtx = false;
    public int $serverBits = 15;
    public int $clientBits = 15;
    /** The accepted offer named server_max_window_bits: the answer must repeat it (§7.1.2.1), even at 15. */
    public bool $serverBitsAsked = false;
    private bool $ourNoCtx = false;
    private int $ourBits = 15;
    private ?\DeflateContext $def = null;
    private ?\InflateContext $inf = null;

    /** @return array<int, array<string,string>> one parameter map per well-formed permessage-deflate offer; a malformed one is dropped */
    public static function parseOffers(string $header): array
    {
        $out = [];
        foreach (\explode(',', $header) as $ext) {
            $parts = \explode(';', $ext);
            if (\strtolower(\trim($parts[0])) !== 'permessage-deflate') {
                continue;
            }
            $params = [];
            $ok = true;
            $n = \count($parts);
            for ($i = 1; $i < $n; $i++) {
                $kv = \explode('=', $parts[$i], 2);
                $k = \strtolower(\trim($kv[0]));
                $hasV = \count($kv) === 2;
                $v = $hasV ? \trim(\trim($kv[1]), '"') : '';
                if (isset($params[$k])) {
                    $ok = false;
                } elseif ($k === 'server_no_context_takeover' || $k === 'client_no_context_takeover') {
                    $ok = !$hasV;
                } elseif ($k === 'server_max_window_bits') {
                    $ok = self::bitsOk($v);
                } elseif ($k === 'client_max_window_bits') {
                    $ok = !$hasV || self::bitsOk($v);
                } else {
                    $ok = false;
                }
                if (!$ok) {
                    break;
                }
                $params[$k] = $v;
            }
            if ($ok) {
                $out[] = $params;
            }
        }
        return $out;
    }

    private static function bitsOk(string $v): bool
    {
        return \ctype_digit($v) && \strlen($v) <= 2 && (int)$v >= 8 && (int)$v <= 15;
    }

    /** Server: the first offer we can satisfy (every well-formed one but server_max_window_bits=8), or null. */
    public static function accept(string $header): ?Pmd
    {
        foreach (self::parseOffers($header) as $p) {
            if (isset($p['server_max_window_bits']) && (int)$p['server_max_window_bits'] === 8) {
                continue;
            }
            $m = new Pmd();
            $m->serverNoCtx = isset($p['server_no_context_takeover']);
            $m->clientNoCtx = isset($p['client_no_context_takeover']);
            $m->serverBitsAsked = isset($p['server_max_window_bits']);
            $m->serverBits = $m->serverBitsAsked ? (int)$p['server_max_window_bits'] : 15;
            $m->clientBits = isset($p['client_max_window_bits']) && $p['client_max_window_bits'] !== ''
                ? (int)$p['client_max_window_bits'] : 15;
            $m->forRole(false);
            return $m;
        }
        return null;
    }

    public function responseHeader(): string
    {
        $h = 'permessage-deflate';
        if ($this->serverNoCtx) {
            $h .= '; server_no_context_takeover';
        }
        if ($this->clientNoCtx) {
            $h .= '; client_no_context_takeover';
        }
        if ($this->serverBitsAsked || $this->serverBits !== 15) {
            $h .= '; server_max_window_bits=' . $this->serverBits;
        }
        if ($this->clientBits !== 15) {
            $h .= '; client_max_window_bits=' . $this->clientBits;
        }
        return $h;
    }

    public static function offerHeader(): string
    {
        return 'permessage-deflate; client_max_window_bits';
    }

    /** Client: the server's answer to {@see offerHeader}; anything we did not allow throws. */
    public static function fromResponse(string $header): Pmd
    {
        $offers = self::parseOffers($header);
        if (\count($offers) !== 1 || \count(\explode(',', $header)) !== 1) {
            throw new HandshakeException('WebSocket handshake failed: server chose an unoffered extension "' . $header . '"');
        }
        $p = $offers[0];
        if (isset($p['client_max_window_bits']) && $p['client_max_window_bits'] === '') {
            throw new HandshakeException('WebSocket handshake failed: client_max_window_bits without a value');
        }
        if (isset($p['client_max_window_bits']) && (int)$p['client_max_window_bits'] === 8) {
            throw new HandshakeException('WebSocket handshake failed: server requires client_max_window_bits=8, which our deflater cannot honour');
        }
        $m = new Pmd();
        $m->serverNoCtx = isset($p['server_no_context_takeover']);
        $m->clientNoCtx = isset($p['client_no_context_takeover']);
        $m->serverBits = isset($p['server_max_window_bits']) ? (int)$p['server_max_window_bits'] : 15;
        $m->clientBits = isset($p['client_max_window_bits']) ? (int)$p['client_max_window_bits'] : 15;
        $m->forRole(true);
        return $m;
    }

    /** Which half of the agreement limits OUR deflater. */
    public function forRole(bool $client): void
    {
        $this->ourNoCtx = $client ? $this->clientNoCtx : $this->serverNoCtx;
        $this->ourBits = $client ? $this->clientBits : $this->serverBits;
    }

    /** One message: SYNC_FLUSH, the 00 00 ff ff tail stripped. */
    public function compress(string $msg): string
    {
        $d = $this->def;
        if ($d === null || $this->ourNoCtx) {
            $nd = \deflate_init(\ZLIB_ENCODING_RAW, ['window' => $this->ourBits]);
            if ($nd === false) {
                throw new \LogicException('permessage-deflate: deflate_init refused window ' . $this->ourBits);
            }
            $d = $nd;
            $this->def = $d;
        }
        $z = \deflate_add($d, $msg, \ZLIB_SYNC_FLUSH);
        $n = \strlen($z);
        if ($n >= 4 && \substr($z, $n - 4) === "\x00\x00\xff\xff") {
            return \substr($z, 0, $n - 4);
        }
        return $z;
    }

    /**
     * One message; null once it grows past $max (1009). Fed in 4 KiB slices so
     * a bomb stops early: the peak is $max plus what one slice inflates to (up
     * to ~4 MiB of zeros), never the whole bomb. Corrupt data throws
     * \UnexpectedValueException (1007).
     */
    public function decompress(string $payload, int $max): ?string
    {
        $f = $this->inf;
        if ($f === null) {
            $ni = \inflate_init(\ZLIB_ENCODING_RAW);
            if ($ni === false) {
                throw new \LogicException('permessage-deflate: inflate_init failed');
            }
            $f = $ni;
            $this->inf = $f;
        }
        $in = $payload . "\x00\x00\xff\xff";
        $out = '';
        $n = \strlen($in);
        for ($p = 0; $p < $n; $p += 4096) {
            $r = \inflate_add($f, \substr($in, $p, 4096), \ZLIB_SYNC_FLUSH);
            if ($r === false) {
                throw new \UnexpectedValueException('permessage-deflate: corrupt payload');
            }
            $out .= $r;
            if (\strlen($out) > $max) {
                return null;
            }
        }
        return $out;
    }
}

/**
 * Set a stream read timeout in seconds.
 *
 * @internal
 */
// Seconds as stream_set_timeout's (int, µs) pair.
function setTimeout(\Resource $s, float $seconds): void
{
    $whole = (int)$seconds;
    \stream_set_timeout($s, $whole, (int)(($seconds - $whole) * 1000000.0));
}

/**
 * Whether the last read on a stream timed out.
 *
 * @internal
 */
function timedOut(\Resource $s): bool
{
    $meta = \stream_get_meta_data($s);
    return isset($meta['timed_out']) && $meta['timed_out'];
}

/**
 * One open WebSocket, server or client side.
 *
 * Obtain one from connect() (client) or as the argument of the session callback
 * given to upgrade() (server). Iterating it with `foreach` yields each incoming
 * Message until the connection ends.
 *
 * ```php
 * $ws = Http\WebSocket\connect('ws://127.0.0.1:8080/');
 * $ws->send('hello');
 * foreach ($ws as $m) {
 *     echo $m->data, "\n";
 * }
 * ```
 *
 * **Reading.** Only one task may read at a time; a second concurrent
 * receive() throws `LogicException`. The connection is kept alive only while
 * something reads it: the read timeout doubles as the keepalive ping timer
 * (see Options::$pingInterval), so a connection nobody reads is never pinged.
 * Code that only sends must still run a reader, even one that discards the
 * messages.
 *
 * **Writing.** send(), sendBinary() and ping() may be called from any task;
 * concurrent writers are serialized, so frames never interleave on the wire.
 * Every message goes out as a single frame.
 *
 * **Closing.** close() starts the closing handshake. Called from the reading
 * task it waits for the peer's answer (up to Options::$closeTimeout); called
 * from any other task it sends the Close frame and returns immediately, and a
 * deadline shuts the socket down if the peer does not answer in time.
 *
 * @see Options
 * @see Message
 * @see upgrade
 * @see connect
 * @example doc_ws_echo
 */
// One WebSocket, either end. Reads happen in whichever task calls receive()
// (one at a time); writes may come from any task and are serialized, so a
// broadcaster never interleaves two frames. The connection is kept alive only
// while someone reads it: the read timeout IS the ping timer.
//
// A Close started outside the reading task never reads: it sends the frame
// and arms a closeTimeout deadline in the connection's scope. A parked reader
// sees the peer's answer; if none comes in time, the deadline shuts the socket
// down, which wakes that reader with EOF (or, with no reader, ends it there).
final class Connection implements \IteratorAggregate
{
    private FrameParser $parser;
    private ?\Async\Mutex $wlock = null;
    private ?\Async\TaskGroup $scope = null;
    private ?\Async\Task $deadline = null;
    /** Task::$id of the task that built this connection (the server session, the client's connect() caller); 0 outside async. */
    private int $owner = 0;
    /** Task::$id of the task that last ran receive(); 0 until one has. */
    private int $lastReader = 0;
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

    /** @internal Built by upgrade() and connect(), never by user code. */
    public function __construct(
        private \Resource $sock,
        private \Buffer\ByteBuffer $buf,
        private bool $client,
        private Options $o,
        private string $protocol = '',
        private ?\Http\Request $request = null,
        private string $remoteAddr = '',
        private ?Pmd $pmd = null,
    ) {
        $this->parser = new FrameParser($buf, !$client, $o->maxFrameSize, $pmd !== null);
        $scope = \Async\Context::currentScope();
        if ($scope !== null) {
            $this->scope = $scope;
            $this->owner = \Async\Scheduler::instance()->current()->id;
            $this->wlock = new \Async\Mutex();
        }
    }

    /**
     * The subprotocol negotiated in the handshake.
     *
     * @return string  the protocol name, or '' when none was negotiated
     */
    public function protocol(): string { return $this->protocol; }
    /**
     * The HTTP upgrade request that opened this connection.
     *
     * @return \Http\Request|null  the request on the server side; null on a client connection
     */
    public function request(): ?\Http\Request { return $this->request; }
    /**
     * The address of the peer.
     *
     * @return string  the client's address on the server side; `host:port` of the URL on the client side
     */
    public function remoteAddr(): string { return $this->remoteAddr; }
    /**
     * Whether messages can still be sent.
     *
     * @return bool  false once the connection is closed or a Close frame has been sent
     */
    public function isOpen(): bool { return !$this->closed && !$this->sentClose; }
    /**
     * The peer's close code, valid once receive() has returned null.
     *
     * @return int  the code from the peer's Close frame; 1005 if that frame carried none; 1006 if no valid Close ever arrived (EOF, a protocol error, an unanswered ping). Never the code this side sent.
     */
    // The peer's Close code (1005 when it carried none); 1006 when no Close came.
    public function closeCode(): int { return $this->gotClose ? $this->peerCode : 1006; }
    /**
     * The reason text from the peer's Close frame.
     *
     * @return string  the reason, or '' when the peer sent none or no Close arrived
     */
    public function closeReason(): string { return $this->gotClose ? $this->peerReason : ''; }

    /**
     * Iterate the incoming messages: `foreach ($ws as $m)` yields each Message
     * until the connection ends. Equivalent to calling receive() until it
     * returns null.
     *
     * @return \Generator  yields Message objects
     */
    // Messages until the connection closes.
    public function getIterator(): \Generator
    {
        while (($m = $this->receive()) !== null) {
            yield $m;
        }
    }

    /**
     * Wait for the next complete message.
     *
     * Fragments are reassembled, compressed messages decompressed, and pings
     * answered with a pong automatically. Returns null once the connection has
     * ended; closeCode() and closeReason() say why. A protocol violation by the
     * peer closes the connection with the matching code (1002 protocol error,
     * 1007 invalid UTF-8 or corrupt compressed data, 1009 message or frame too
     * big) and also yields null.
     *
     * @return Message|null  the next message, or null when the connection is over
     * @throws \LogicException  when another task is already reading this connection
     */
    // The next message, or null once closed ({@see closeCode} says why).
    public function receive(): ?Message
    {
        if ($this->reading) {
            throw new \LogicException('Http\\WebSocket\\Connection::receive() is already running in another task');
        }
        if ($this->closed) {
            return null;
        }
        $this->reading = true;
        if ($this->scope !== null) {
            $this->lastReader = \Async\Scheduler::instance()->current()->id;
        }
        try {
            return $this->readMessage();
        } finally {
            $this->reading = false;
            $this->releaseFd();
        }
    }

    /**
     * Send a text message as one frame.
     *
     * May be called from any task. The data must be valid UTF-8: a conforming
     * peer closes the connection with 1007 on anything else.
     *
     * @param string $data  the message
     * @throws ConnectionClosedException  when the connection is closed or closing
     */
    public function send(string $data): void
    {
        $this->sendData(Opcode::TEXT, $data);
    }

    /**
     * Send a binary message as one frame.
     *
     * May be called from any task.
     *
     * @param string $data  the message bytes
     * @throws ConnectionClosedException  when the connection is closed or closing
     */
    public function sendBinary(string $data): void
    {
        $this->sendData(Opcode::BINARY, $data);
    }

    /**
     * Send a ping frame. The peer's pong is consumed by the reader and not
     * delivered as a message.
     *
     * @param string $payload  up to 125 bytes echoed back in the pong
     * @throws \ValueError  when the payload is longer than 125 bytes
     * @throws ConnectionClosedException  when the connection is closed or closing
     */
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
     * Start the closing handshake by sending a Close frame.
     *
     * Called from the reading task (the task that last ran receive(), or the one
     * that opened the connection before any receive()) it then waits for the
     * peer's Close, up to Options::$closeTimeout, discarding any data frames
     * that arrive meanwhile. Called from any other task, or while a receive() is
     * in flight, it returns at once; a deadline of Options::$closeTimeout shuts
     * the socket down if the peer does not answer, and the reader's receive()
     * returns null with closeCode() 1006. Closing an already closing or closed
     * connection does nothing.
     *
     * @param int $code  1000-1003, 1007-1014 or 3000-4999
     * @param string $reason  UTF-8 text of at most 123 bytes
     * @throws \ValueError  for a code that cannot be sent, a reason over 123 bytes, or a reason that is not valid UTF-8; nothing is sent
     * @example doc_ws_echo
     */
    // Start the closing handshake. From any task but the reader (a broadcaster
    // kicking a client), or while a read is in flight, it sends the Close and
    // arms the closeTimeout deadline — the reader's next or current receive()
    // sees the answer (or the deadline's EOF) and returns null. The reader with
    // no read in flight waits here, up to closeTimeout, for the peer's Close,
    // discarding data frames. The reader is the task that last ran receive(),
    // or the owner until one has — so a reader parked between two receive()
    // calls never finds its read taken.
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!closeCodeSendable($code)) {
            throw new \ValueError('Http\\WebSocket\\Connection::close(): Argument #1 ($code) must be 1000-1003, 1007-1014 or 3000-4999');
        }
        if (\strlen($reason) > 123) {
            throw new \ValueError('Http\\WebSocket\\Connection::close(): Argument #2 ($reason) must be at most 123 bytes');
        }
        if (!utf8Ok($reason)) {
            throw new \ValueError('Http\\WebSocket\\Connection::close(): Argument #2 ($reason) must be valid UTF-8');
        }
        if ($this->closed || $this->sentClose) {
            return;
        }
        if (!$this->sendClose($code, $reason)) {
            return;
        }
        if ($this->reading || !$this->isReader()) {
            $this->armDeadline();
            return;
        }
        $this->awaitPeerClose();
    }

    private function isReader(): bool
    {
        if ($this->scope === null) {
            return true;
        }
        $me = \Async\Scheduler::instance()->current()->id;
        return $me === ($this->lastReader !== 0 ? $this->lastReader : $this->owner);
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
        // An EMPTY message always goes plain: deflate_add('') is a no-op, so it
        // would go out as RSV1 with no payload, and the peer's inflater would
        // wait inside a stored block and garble the next message.
        if ($this->pmd !== null && $data !== '' && \strlen($data) >= $this->o->compressionMinBytes) {
            $ok = !$this->closed && !$this->sentClose && $this->writeRaw($data, $op);
        } else {
            $ok = $this->writeFrame($op, $data, false);
        }
        if (!$ok) {
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
            if ($compressed) {
                $pmd = $this->pmd;
                try {
                    $plain = $pmd === null ? null : $pmd->decompress($data, $this->o->maxMessageSize);
                } catch (\UnexpectedValueException $e) {
                    $this->fail(1007);
                    return null;
                }
                if ($plain === null) {
                    $this->fail(1009);
                    return null;
                }
                $data = $plain;
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

    /**
     * Bytes to the wire under the write lock. With $zop >= 0, $bytes is a
     * message to compress and frame here, inside the lock: the deflater's
     * stream is shared, so its order must be the frames' order on the wire.
     */
    private function writeRaw(string $bytes, int $zop = -1): bool
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
                $out = $bytes;
                $pmd = $this->pmd;
                if ($zop >= 0 && $pmd !== null) {
                    $out = encodeFrame($zop, $pmd->compress($bytes), true, true, $this->nextMask());
                }
                $ok = \fwrite($this->sock, $out) === \strlen($out);
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

/**
 * Whether a comma-separated header value contains a token.
 *
 * @internal
 */
// Header tokens (comma-separated, case-insensitive) — `Connection: keep-alive, Upgrade`.
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
 * Answer a WebSocket upgrade request inside an `Http\Server` handler.
 *
 * Return the result from the handler. When the request is a valid upgrade, the
 * result is a `101 Switching Protocols` response and, once it is sent,
 * `$session` runs with the new Connection in its own fiber. When the request
 * is not a valid upgrade, the result is an ordinary error response, not an
 * exception:
 *
 * - 400: not `GET`, not HTTP/1.1, a missing or malformed `Sec-WebSocket-Key`,
 *   no `websocket` token in `Upgrade`, or no `upgrade` token in `Connection`.
 * - 426 with `Sec-WebSocket-Version: 13`: any other protocol version.
 * - 403: Options::$allowedOrigins is set and the `Origin` header is not in it.
 *
 * When `$session` returns, a connection still open is closed with 1000. If it
 * throws, the connection is closed with 1011 and the exception is rethrown
 * into the server's own error path. The session holds the server's
 * `maxConnections` permit for its whole life. `Server::stop()` closes every
 * open session with 1001 "server shutdown".
 *
 * ```php
 * (new Server('tcp://0.0.0.0:8080'))->serve(function (Request $req): Response {
 *     return Http\WebSocket\upgrade($req, function (Connection $ws): void {
 *         foreach ($ws as $m) {
 *             $ws->send('echo: ' . $m->data);
 *         }
 *     });
 * });
 * ```
 *
 * @param \Http\Request $req  the request the handler received
 * @param callable $session  called as `function (Connection $ws): void`
 * @param Options|null $o  settings; defaults when null
 * @return \Http\Response  the 101 response, or a 400, 426 or 403 error response
 * @see connect
 * @see handler
 * @example doc_ws_echo
 */
// Answer a WebSocket upgrade request. A request that is not one answers an
// ordinary 400/426/403 — hand it back from the handler like any Response.
// $session receives the Connection and runs in the connection's own fiber;
// when it returns, an open connection is closed with 1000.
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
    $pmd = null;
    if ($o->compression && $h->get('Sec-WebSocket-Extensions') !== '') {
        $pmd = Pmd::accept($h->get('Sec-WebSocket-Extensions'));
        if ($pmd !== null) {
            $res->header('Sec-WebSocket-Extensions', $pmd->responseHeader());
        }
    }
    $fn = \Closure::fromCallable($session);
    return $res->takeover(function (\Resource $sock, \Buffer\ByteBuffer $buf, \Http\Server $server) use ($fn, $o, $proto, $req, $pmd): void {
        $c = new Connection($sock, $buf, false, $o, $proto, $req, $req->remoteAddr, $pmd);
        $c->runSession($fn, $server);
    });
}

/**
 * Open a WebSocket client connection to a `ws://` or `wss://` URL.
 *
 * `wss://` uses TLS with SNI and certificate verification; configure it through
 * Options::$sslContext. The default port is 80 for `ws://` and 443 for `wss://`.
 * The client checks the server's answer itself: the status must be 101,
 * `Sec-WebSocket-Accept` must match, and a subprotocol or extension in the
 * answer must be one that was offered. Redirects are not followed.
 * Options::$connectTimeout bounds the TCP/TLS connect and the handshake
 * response together, as one deadline.
 *
 * Outside `Async\async()` the call blocks the process on ordinary sockets;
 * inside it, it suspends only the calling fiber, so many connections can run
 * concurrently.
 *
 * ```php
 * $ws = Http\WebSocket\connect('wss://example.com/feed');
 * $ws->send('hello');
 * foreach ($ws as $m) {
 *     echo $m->data, "\n";
 * }
 * ```
 *
 * @param string $url  `ws://host[:port]/path?query` or `wss://...`
 * @param Options|null $o  settings; defaults when null
 * @param array<string,string> $headers  extra request headers (`Origin`, `Authorization`, …)
 * @return Connection  the open connection
 * @throws HandshakeException  for any other scheme, an empty host, CR/LF/NUL in the URL, a failed connect, a timeout, or a handshake the server refused or answered incorrectly
 * @throws \ValueError  when a header name or value is empty-named, contains CR, LF or NUL, or names `Host`, `Upgrade`, `Connection` or a `Sec-WebSocket-*` header
 * @see upgrade
 * @example doc_ws_echo
 */
// Open a WebSocket to ws:// or wss://. Throws HandshakeException when the
// server does not complete the upgrade. Redirects are not followed.
// connectTimeout bounds the connect and the handshake together.
function connect(string $url, ?Options $o = null, array<string, string> $headers = []): Connection
{
    $o = $o ?? new Options();
    // The host, path and query go into the request line and the Host header
    // verbatim: a CR, LF or NUL there is header injection, whatever parse_url
    // makes of it.
    if (\strpbrk($url, "\r\n\0") !== false) {
        throw new HandshakeException('WebSocket handshake failed: CR, LF or NUL in the URL');
    }
    $u = \parse_url($url);
    $scheme = \is_array($u) && isset($u['scheme']) ? \strtolower((string)$u['scheme']) : '';
    if ($scheme !== 'ws' && $scheme !== 'wss') {
        throw new HandshakeException('WebSocket handshake failed: unsupported scheme "' . $scheme . '"');
    }
    $host = isset($u['host']) ? (string)$u['host'] : '';
    if ($host === '') {
        throw new HandshakeException('WebSocket handshake failed: no host in "' . $url . '"');
    }
    $tls = $scheme === 'wss';
    $port = isset($u['port']) ? (int)$u['port'] : ($tls ? 443 : 80);
    $path = (isset($u['path']) && $u['path'] !== '' ? (string)$u['path'] : '/')
        . (isset($u['query']) ? '?' . (string)$u['query'] : '');
    foreach ($headers as $name => $v) {
        checkHeader((string)$name, $v);
    }
    $deadline = \__mc_monotonic_f() + $o->connectTimeout;
    $ctx = null;
    if ($tls) {
        $peer = \strlen($host) > 1 && $host[0] === '[' ? \substr($host, 1, \strlen($host) - 2) : $host;
        $ctx = \stream_context_create(['ssl' => $o->sslContext + ['peer_name' => $peer, 'SNI_enabled' => true]]);
    }
    $errno = 0;
    $errstr = '';
    $sock = \stream_socket_client(($tls ? 'tls://' : 'tcp://') . $host . ':' . $port,
        $errno, $errstr, $o->connectTimeout, \STREAM_CLIENT_CONNECT, $ctx);
    if ($sock === false) {
        throw new HandshakeException('WebSocket connect to ' . $url . ' failed: ' . $errstr);
    }
    try {
        $hostHdr = $host . (($tls && $port === 443) || (!$tls && $port === 80) ? '' : ':' . $port);
        return handshake($sock, $o, $path, $hostHdr, $headers, $deadline, $host . ':' . $port);
    } catch (\Throwable $e) {
        \fclose($sock);
        throw $e;
    }
}

/**
 * Validate a user header for connect().
 *
 * @internal
 */
// A user header for connect(): no CR/LF/NUL, a non-empty name without ':', never a handshake header.
function checkHeader(string $name, string $v): void
{
    $arg = 'Http\\WebSocket\\connect(): Argument #3 ($headers) ';
    if ($name === '') {
        throw new \ValueError($arg . 'must not contain an empty header name');
    }
    if (\strpbrk($name . $v, "\r\n\0") !== false) {
        throw new \ValueError($arg . 'must not contain CR, LF or NUL');
    }
    if (\strpos($name, ':') !== false) {
        throw new \ValueError($arg . 'must not contain ":" in a header name');
    }
    $ln = \strtolower($name);
    if ($ln === 'host' || $ln === 'upgrade' || $ln === 'connection' || \strncmp($ln, 'sec-websocket-', 14) === 0) {
        throw new \ValueError($arg . 'must not set ' . $name);
    }
}

/**
 * The client half of the upgrade over an open socket.
 *
 * @internal
 * @param array<string,string> $headers
 */
// The client half of the upgrade over an open socket. Throws on any failure;
// the caller owns (and closes) the socket until a Connection is returned.
function handshake(\Resource $sock, Options $o, string $path, string $hostHdr, array<string, string> $headers, float $deadline, string $remote): Connection
{
    if (\Async\Context::currentScope() !== null) {
        \stream_set_blocking($sock, false);
    }
    $key = \base64_encode(\random_bytes(16));
    $req = 'GET ' . $path . " HTTP/1.1\r\nHost: " . $hostHdr
        . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . $key
        . "\r\nSec-WebSocket-Version: 13\r\n";
    if (\count($o->protocols) > 0) {
        $req .= 'Sec-WebSocket-Protocol: ' . \implode(', ', $o->protocols) . "\r\n";
    }
    if ($o->compression) {
        $req .= 'Sec-WebSocket-Extensions: ' . Pmd::offerHeader() . "\r\n";
    }
    foreach ($headers as $name => $v) {
        $req .= $name . ': ' . $v . "\r\n";
    }
    $req .= "\r\n";
    if (\fwrite($sock, $req) !== \strlen($req)) {
        throw new HandshakeException('WebSocket handshake failed: could not send the request');
    }

    $buf = new \Buffer\ByteBuffer();
    while (true) {
        $end = $buf->indexOf("\r\n\r\n");
        if ($end > 16384 || ($end < 0 && $buf->length() > 16388)) {
            throw new HandshakeException('WebSocket handshake failed: response head too large');
        }
        if ($end >= 0) {
            break;
        }
        $left = $deadline - \__mc_monotonic_f();
        if ($left <= 0.0) {
            throw new HandshakeException('WebSocket handshake failed: timed out waiting for the response');
        }
        setTimeout($sock, $left);
        $chunk = \fread($sock, 8192);
        if ($chunk === '' || $chunk === false) {
            if (timedOut($sock)) {
                throw new HandshakeException('WebSocket handshake failed: timed out waiting for the response');
            }
            throw new HandshakeException('WebSocket handshake failed: connection closed before the response');
        }
        $buf->append($chunk);
    }
    $lines = \Http\splitHead($buf->read($end));
    $buf->skip(4);
    $first = \count($lines) > 0 ? $lines[0] : '';
    if (\strncmp($first, 'HTTP/1.', 7) !== 0) {
        throw new HandshakeException('WebSocket handshake failed: not an HTTP/1.x response');
    }
    $status = \explode(' ', $first, 3);
    $code = \count($status) >= 2 ? (int)$status[1] : 0;
    $rest = [];
    for ($i = 1; $i < \count($lines); $i++) {
        $rest[] = $lines[$i];
    }
    $h = \Http\Headers::fromLines($rest);
    $proto = $h->get('Sec-WebSocket-Protocol');
    $ext = $h->get('Sec-WebSocket-Extensions');
    $fail = '';
    if ($code !== 101) {
        $fail = 'HTTP ' . $code . (\count($status) >= 3 && $status[2] !== '' ? ' ' . $status[2] : '');
    } elseif (!hasToken($h->get('Upgrade'), 'websocket') || !hasToken($h->get('Connection'), 'upgrade')) {
        $fail = 'missing Upgrade/Connection';
    } elseif ($h->get('Sec-WebSocket-Accept') !== acceptKey($key)) {
        $fail = 'bad Sec-WebSocket-Accept';
    } elseif ($proto !== '' && !\in_array($proto, $o->protocols, true)) {
        $fail = 'server chose an unoffered subprotocol "' . $proto . '"';
    } elseif ($ext !== '' && !$o->compression) {
        $fail = 'server chose an unoffered extension "' . $ext . '"';
    }
    if ($fail !== '') {
        throw new HandshakeException('WebSocket handshake failed: ' . $fail);
    }
    $pmd = $ext === '' ? null : Pmd::fromResponse($ext);
    return new Connection($sock, $buf, true, $o, $proto, null, $remote, $pmd);
}

/**
 * Event callbacks for a connection, as an alternative to a `foreach` loop.
 *
 * Implement it and pass it to handler() (server, as the session of upgrade())
 * or run() (client). The calls arrive in this order: onOpen once, onMessage per
 * message, onClose once, whichever way the connection ends.
 *
 * @see handler
 * @see run
 * @example doc_ws_handler
 */
interface Handler
{
    /**
     * The connection is ready; called before the first message is read.
     *
     * @param Connection $c  the connection
     */
    public function onOpen(Connection $c): void;
    /**
     * A complete message arrived.
     *
     * @param Connection $c  the connection
     * @param Message $m  the message
     */
    public function onMessage(Connection $c, Message $m): void;
    /**
     * The connection ended; called exactly once, after any onError.
     *
     * @param Connection $c  the connection
     * @param int $code  the peer's close code (see Connection::closeCode(); 1006 if no Close arrived)
     * @param string $reason  the peer's close reason, possibly empty
     */
    public function onClose(Connection $c, int $code, string $reason): void;
    /**
     * onOpen or onMessage threw; the connection is then closed with 1011. An
     * exception thrown by onError itself is discarded.
     *
     * @param Connection $c  the connection
     * @param \Throwable $e  the exception
     */
    public function onError(Connection $c, \Throwable $e): void;
}

/**
 * Drive a connection through a Handler until it ends.
 *
 * Calls onOpen, then onMessage for each message, then onClose exactly once,
 * whichever way the loop ends. An exception from onOpen or onMessage goes to
 * onError and the connection is closed with 1011; an exception from onError is
 * discarded. A cancelled task is not reported to onError. This is the
 * client-side counterpart of handler(); it reads, so it also keeps the
 * connection alive.
 *
 * @param Connection $c  the connection to drive
 * @param Handler $h  the callbacks
 * @see handler
 */
// Drive a Connection through a Handler: onOpen, onMessage per message, and
// onClose exactly once. An exception from onOpen/onMessage goes to onError and
// closes the connection with 1011; one from onError is dropped.
function run(Connection $c, Handler $h): void
{
    try {
        $h->onOpen($c);
        while (($m = $c->receive()) !== null) {
            $h->onMessage($c, $m);
        }
    } catch (\Async\CancelledException $e) {
        throw $e;
    } catch (\Throwable $e) {
        try {
            $h->onError($c, $e);
        } catch (\Throwable $e2) {
        }
        if ($c->isOpen()) {
            try {
                $c->close(1011);
            } catch (\Throwable $e3) {
            }
        }
    } finally {
        $h->onClose($c, $c->closeCode(), $c->closeReason());
    }
}

/**
 * Turn a Handler into a session callback for upgrade().
 *
 * `upgrade($req, handler($h), $o)` runs run() over each accepted connection.
 *
 * @param Handler $h  the callbacks
 * @return \Closure  a session for upgrade()
 * @see run
 * @example doc_ws_handler
 */
// A session for {@see upgrade}: runs the Handler over each accepted connection.
function handler(Handler $h): \Closure
{
    return function (Connection $c) use ($h): void {
        run($c, $h);
    };
}

/**
 * A set of connections that a message can be sent to at once.
 *
 * Closed connections, and ones whose send fails, are dropped from the set. The
 * broadcast is sequential: each send returns only once that client has taken
 * the whole frame, so a slow client delays the ones after it. It suits small
 * rooms and small messages; for a wide or large fan-out, give each connection
 * its own writer task fed from a bounded queue.
 *
 * @see Connection
 * @example doc_ws_handler
 */
// A set of connections to broadcast to. Closed ones are skipped and dropped.
final class Hub
{
    /** @var array<int, Connection> */
    private array<int, Connection> $conns = [];

    /**
     * Add a connection to the set. Adding one already in the set changes nothing.
     *
     * @param Connection $c  the connection
     */
    public function add(Connection $c): void
    {
        $this->conns[\spl_object_id($c)] = $c;
    }

    /**
     * Remove a connection from the set. Removing one that is not in it changes nothing.
     *
     * @param Connection $c  the connection
     */
    public function remove(Connection $c): void
    {
        unset($this->conns[\spl_object_id($c)]);
    }

    /**
     * The number of connections in the set, closed ones not yet dropped included.
     *
     * @return int  the number of connections
     */
    public function count(): int
    {
        return \count($this->conns);
    }

    /**
     * Send a message to every open connection in the set.
     *
     * @param string $data  the message
     * @param bool $binary  true to send it as a binary message, false for text
     * @return int  how many connections the message was sent to
     */
    // @return int how many connections the message was sent to
    public function broadcast(string $data, bool $binary = false): int
    {
        $sent = 0;
        foreach ($this->conns as $id => $c) {
            if (!$c->isOpen()) {
                unset($this->conns[$id]);
                continue;
            }
            try {
                if ($binary) {
                    $c->sendBinary($data);
                } else {
                    $c->send($data);
                }
                $sent = $sent + 1;
            } catch (\Throwable $e) {
                unset($this->conns[$id]);
            }
        }
        return $sent;
    }
}

}
