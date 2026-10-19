<?php

/**
 * pdo_mysql — the MySQL driver behind PDO, speaking the client/server protocol
 * over a plain socket (no libmysqlclient). Implements the {@see __McPdoDrv} seam.
 *
 * Text protocol with client-side (emulated) prepares, as pdo_mysql does by default;
 * ATTR_EMULATE_PREPARES=false switches a statement to the binary protocol
 * (COM_STMT_PREPARE / EXECUTE / CLOSE) with typed rows.
 * The classic EOF packets are used throughout: CLIENT_DEPRECATE_EOF is not
 * requested, so there is one result framing to validate, not two.
 *
 * Parsing is ord()/substr() on purpose (no pack()/unpack()): the binary prelude
 * is gated on the PROGRAM calling it, and the row loop is the hot path.
 *
 * Deliberate gaps: unix sockets, compression, COM_STMT_SEND_LONG_DATA (a value
 * travels inline, as pdo_mysql sends it).
 */

/** One ERR packet. */
final class __McPdoMyErr
{
    public int $no = 0;
    public string $state = 'HY000';
    public string $msg = '';
}

/**
 * One result of a COM_QUERY. A class and not a keyed array: it mixes rows,
 * strings and ints, and a mixed-value array loses its element type. A
 * multi-statement query is a chain through ->next.
 */
final class __McPdoMyResult
{
    /** @var array<int,string> */
    public array $names = [];
    /** @var array<int,string> */
    public array $tables = [];
    /** @var array<int,int> MYSQL_TYPE_* */
    public array $types = [];
    /** @var array<int,int> column flags (NOT_NULL, PRI_KEY, UNSIGNED, ...) */
    public array $flags = [];
    /** @var array<int,int> display length from the column definition */
    public array $lens = [];
    /** @var array<int,int> decimals */
    public array $decs = [];
    /** @var array<int,array<int,?string>> */
    public array $rows = [];
    public bool $isSet = false;
    /** Rows are binary-protocol cells (see parseBinRow), not text. */
    public bool $bin = false;
    public int $affected = 0;
    public int $insertId = 0;
    public int $status = 0;
    public int $warnings = 0;
    public ?__McPdoMyErr $err = null;
    public ?__McPdoMyResult $next = null;
    /** Unbuffered: only the header was read, the rows are still on the wire (see __McPdoMyWire::cursorRow). */
    public bool $stream = false;
    /** A streamed result whose closing EOF/ERR has been read. */
    public bool $done = false;
    /** A done streamed result the server promised more behind: they are read by nextRowset() or when the cursor is drained. */
    public bool $more = false;
    /** The statement made this result its current one (php's pending-result-sets error until it does). */
    public bool $entered = false;
}

/** The reply to COM_STMT_PREPARE: statement id and the counts the server announced. */
final class __McPdoMyPrep
{
    public int $id = 0;
    public int $cols = 0;
    public int $params = 0;
    public int $warnings = 0;
    public ?__McPdoMyErr $err = null;
}

final class __McPdoMyWire
{
    // client capability flags
    private const CLIENT_LONG_PASSWORD = 0x1;
    private const CLIENT_FOUND_ROWS = 0x2;
    private const CLIENT_LONG_FLAG = 0x4;
    private const CLIENT_CONNECT_WITH_DB = 0x8;
    private const CLIENT_SSL = 0x800;
    private const CLIENT_IGNORE_SPACE = 0x100;
    private const CLIENT_PROTOCOL_41 = 0x200;
    private const CLIENT_TRANSACTIONS = 0x2000;
    private const CLIENT_SECURE_CONNECTION = 0x8000;
    private const CLIENT_MULTI_STATEMENTS = 0x10000;
    private const CLIENT_MULTI_RESULTS = 0x20000;
    private const CLIENT_PS_MULTI_RESULTS = 0x40000;
    private const CLIENT_PLUGIN_AUTH = 0x80000;
    private const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 0x200000;
    /** Largest packet a server may send for anything but a row (handshake, auth, column definition, OK, ERR). */
    private const CTRL_CAP = 1048576;
    /** Largest row: a text value is at most 1 GiB (max_allowed_packet's ceiling). */
    private const ROW_CAP = 1073741824;
    private const MAX_COLUMNS = 4096;
    /** The protocol's own limit: the parameter count is a 16-bit field. */
    private const MAX_PARAMS = 65535;

    public bool $dead = false;
    public string $serverVersion = '';
    public int $connId = 0;
    /** The status flags of the latest OK/EOF packet (SERVER_STATUS_*). */
    public int $status = 0;
    /** The last OK packet's AUTO_INCREMENT id; 0 after any other command, as mysql_insert_id(). */
    public int $insertId = 0;
    /** The endpoint, for ATTR_CONNECTION_STATUS. */
    public string $host = '';
    private int $port = 0;
    /** What a second connection needs to log in again (see cancelRequest). */
    private string $kUser = '';
    /** The password lives in a static table, never in an instance property: a dump of the object graph (print_r, json_encode, (array)) must not show it. */
    private int $kPwId = 0;
    /** @var array<int,string> */
    private static array $pwStore = [];
    private static int $pwSeq = 0;
    private int $kCs = 0;
    private ?__McPdoTlsOpts $kTls = null;
    /** libmysql's prefix is absent; php prints `SQLSTATE[HY000] [2002] ...` for every connect failure. */
    private bool $connecting = true;
    private bool $closed = false;
    /** @var array<int,int> statement ids whose COM_STMT_CLOSE has not been sent yet (the command has no reply, so it only needs a free wire) */
    private array $owed = [];
    /** One request/response cycle owns the wire; a second fiber on the same connection waits (see enter()). */
    private bool $busy = false;
    /** An unbuffered result whose rows are still on the wire: the connection is held until it ends (see enter()). */
    public ?__McPdoMyResult $openCursor = null;
    private ?object $curFiber = null;
    private bool $curBin = false;
    /** A cursorRow() is parked in a read: nobody else may touch the socket. */
    private bool $reading = false;
    /** A command was sent and its last result packet has not arrived: the stream is mid-protocol. */
    private bool $inflight = false;
    /** The KILL QUERY connection of cancelRequest(): its own failures must never ask for another cancel. */
    private bool $noCancel = false;
    private float $timeout = 30.0;
    /** ATTR_TIMEOUT was set by the program: only then does it also bound the idle wait for a response (see fill()). */
    public bool $explicitTimeout = false;
    /** A command was sent and not one byte of its response has arrived: the server is executing it. */
    private bool $awaiting = false;
    /** Absolute deadline of a close/drain in progress (0 = none): while set, no wait is idle, every wait ends by then, and the stream timeout is the same bound. */
    private float $drainDl = 0.0;
    /** Inside a logical packet (a 0xFFFFFF frame was full): its next frame is not the start of anything. */
    private bool $midPacket = false;
    /** The peer hung up or a write failed: it is gone. */
    private bool $peerGone = false;
    /** Connect + authentication budget (monotonic seconds, 0 = none); a drip-feeding server cannot outlast it. */
    private float $authDeadline = 0.0;
    private int $seq = 0;
    /** The command was sent and no packet of its response has been read yet. */
    private bool $respStart = false;
    private int $srvCaps = 0;
    private string $scramble = '';
    private string $plugin = '';
    private int $cliCaps = 0;
    /** The session runs inside TLS (caching_sha2 full authentication then sends the password in the clear, as mysqlnd). */
    private bool $tls = false;
    /** @var resource */
    private $s;
    private string $buf = '';
    private int $pos = 0;
    /** Offset after the last lstr(). */
    private int $lp = 0;

    public function __construct(string $host, int $port, float $timeout)
    {
        $this->host = $host;
        $this->port = $port;
        $ip = $host;
        if (\strpos($host, ':') === false && \ip2long($host) === false) {
            $ip = \gethostbyname($host);
            if ($ip === $host) {
                $gai = \PHP_OS_FAMILY === 'Darwin'
                    ? 'nodename nor servname provided, or not known' : 'Name or service not known';
                throw __McPdo::connectFail('HY000', 2002,
                    'php_network_getaddresses: getaddrinfo for ' . $host . ' failed: ' . $gai);
            }
        }
        $en = 0;
        $es = '';
        $s = \stream_socket_client('tcp://' . (\strpos($ip, ':') === false ? $ip : '[' . $ip . ']')
            . ':' . (string) $port, $en, $es, $timeout);
        if ($s === false) {
            throw __McPdo::connectFail('HY000', 2002, $es);
        }
        $this->s = $s;
        __McPdo::noDelay($s);
        $this->setTimeout($timeout);
        $this->authDeadline = $timeout > 0.0 ? \__mc_monotonic_f() + 2.0 * $timeout : 0.0;
    }

    public function timeoutValue(): float { return $this->timeout; }

    /** Close/drain bound: at most DRAIN_SECONDS (the connection timeout when that is shorter), however long the server idles. */
    private const DRAIN_SECONDS = 5.0;

    private function startDrain(): void
    {
        $b = $this->timeout > 0.0 && $this->timeout < self::DRAIN_SECONDS ? $this->timeout : self::DRAIN_SECONDS;
        $this->drainDl = \__mc_monotonic_f() + $b;
        \stream_set_timeout($this->s, (int) $b, (int) (($b - (float) (int) $b) * 1000000.0));
    }

    private function endDrain(): void
    {
        $this->drainDl = 0.0;
        if (!$this->dead) { $this->setTimeout($this->timeout); }
    }


    /** The collation id the handshake announced (mysql: utf8mb4_0900_ai_ci, mariadb: its own default). */
    public function charsetId(): int { return $this->kCs; }

    /** A reset is not the cursor owner's to refuse: another fiber's open result is not ours to drain. */
    public function assertCursorOwner(): void
    {
        if ($this->openCursor !== null && !$this->dead && \Runtime\AsyncHook::active() && \Fiber::getCurrent() !== $this->curFiber) {
            throw __McPdo::fail('HY000', 0, false, 'cannot reset a connection whose open cursor belongs to another fiber', true);
        }
    }

    public function setTimeout(float $t): void
    {
        $this->timeout = $t;
        // php: 0 = unlimited; a zero stream timeout would instead fail every read at once
        if ($t <= 0.0) { $t = 86400.0; }
        $sec = (int) $t;
        \stream_set_timeout($this->s, $sec, (int) (($t - $sec) * 1000000));
    }

    // ── byte helpers ────────────────────────────────────────────────────────

    public static function le16(string $b, int $o): int
    {
        return \ord($b[$o]) | (\ord($b[$o + 1]) << 8);
    }

    public static function le24(string $b, int $o): int
    {
        return \ord($b[$o]) | (\ord($b[$o + 1]) << 8) | (\ord($b[$o + 2]) << 16);
    }

    public static function le32(string $b, int $o): int
    {
        return \ord($b[$o]) | (\ord($b[$o + 1]) << 8) | (\ord($b[$o + 2]) << 16) | (\ord($b[$o + 3]) << 24);
    }

    /** $n little-endian bytes of $v (n <= 8). */
    public static function putLe(int $v, int $n): string
    {
        $s = '';
        for ($i = 0; $i < $n; $i++) { $s .= \chr(($v >> (8 * $i)) & 255); }
        return $s;
    }

    /** IEEE 754 binary64 as 8 little-endian bytes (the inverse of f64). */
    public static function putF64(float $v): string
    {
        if ($v !== $v) { return "\0\0\0\0\0\0\xf8\x7f"; }
        $neg = $v < 0.0 || ($v === 0.0 && \fdiv(1.0, $v) < 0.0);
        $a = $neg ? -$v : $v;
        $sign = $neg ? 0x80000000 : 0;
        if ($a > 1.7976931348623157e308) { return self::putLe(0, 4) . self::putLe(0x7FF00000 | $sign, 4); }
        if ($a === 0.0) { return self::putLe(0, 4) . self::putLe($sign, 4); }
        $e = 0;
        while ($a >= 2.0) { $a *= 0.5; $e++; }
        while ($a < 1.0) { $a *= 2.0; $e--; }
        if ($e >= -1022) {
            $m = (int) (($a - 1.0) * 4503599627370496.0);
            $be = $e + 1023;
        } else {
            $m = (int) ($a * (2.0 ** ($e + 1074)));
            $be = 0;
        }
        return self::putLe($m & 0xFFFFFFFF, 4) . self::putLe(($be << 20) | ($m >> 32) | $sign, 4);
    }

    /** Length-encoded integer. */
    public static function lenencInt(int $v): string
    {
        if ($v < 251) { return \chr($v); }
        if ($v < 65536) { return "\xfc" . self::putLe($v, 2); }
        if ($v < 16777216) { return "\xfd" . self::putLe($v, 3); }
        return "\xfe" . self::putLe($v, 8);
    }

    /**
     * The length-encoded integer at $o: its value, -1 for the NULL marker 0xFB,
     * -2 when truncated, not a valid length (0xFF) or above $max. The default cap is
     * 0x7FFFFFFF: no length, count or offset a server sends is larger, and a bigger one
     * must never reach an addition ($o + $l wraps negative in compiled ints). Affected
     * rows / insert id pass PHP_INT_MAX.
     */
    public static function lenencAt(string $b, int $o, int $max = 0x7FFFFFFF): int
    {
        $n = \strlen($b);
        if ($o >= $n) { return -2; }
        $c = \ord($b[$o]);
        if ($c < 251) { return $c; }
        if ($c === 251) { return -1; }
        if ($c === 252) { return $o + 3 <= $n ? self::le16($b, $o + 1) : -2; }
        if ($c === 253) { return $o + 4 <= $n ? self::le24($b, $o + 1) : -2; }
        if ($c === 254) {
            if ($o + 9 > $n) { return -2; }
            $hi = self::le32($b, $o + 5);
            if ($hi > 0x7FFFFFFF) { return -2; }
            $v = self::le32($b, $o + 1) | ($hi << 32);
            return $v > $max ? -2 : $v;
        }
        return -2;
    }

    /** Bytes the length prefix at $o occupies. */
    public static function lenencSize(string $b, int $o): int
    {
        $c = \ord($b[$o]);
        return $c < 252 ? 1 : ($c === 252 ? 3 : ($c === 253 ? 4 : ($c === 254 ? 9 : 1)));
    }

    /** Big-endian unsigned bytes as a decimal string (BIT columns reach 64 bits, PHP_INT_MAX is 63). */
    public static function beToDec(string $b): string
    {
        /** @var array<int,int> little-endian decimal digits */
        $d = [0];
        $n = \strlen($b);
        for ($i = 0; $i < $n; $i++) {
            $carry = \ord($b[$i]);
            $m = \count($d);
            for ($j = 0; $j < $m; $j++) {
                $v = $d[$j] * 256 + $carry;
                $d[$j] = $v % 10;
                $carry = \intdiv($v, 10);
            }
            while ($carry > 0) { $d[] = $carry % 10; $carry = \intdiv($carry, 10); }
        }
        $s = '';
        for ($j = \count($d) - 1; $j >= 0; $j--) { $s .= \chr(48 + $d[$j]); }
        return $s;
    }

    // ── authentication scrambles (pure: known vectors test them) ────────────

    /** mysql_native_password: SHA1(pw) XOR SHA1(scramble . SHA1(SHA1(pw))). Empty password, empty response. */
    public static function nativeAuth(string $pw, string $scramble): string
    {
        if ($pw === '') { return ''; }
        $h1 = \sha1($pw, true);
        return $h1 ^ \sha1($scramble . \sha1($h1, true), true);
    }

    /** caching_sha2_password fast path: SHA256(pw) XOR SHA256(SHA256(SHA256(pw)) . scramble). */
    public static function sha2Auth(string $pw, string $scramble): string
    {
        if ($pw === '') { return ''; }
        $h1 = \hash('sha256', $pw, true);
        return $h1 ^ \hash('sha256', \hash('sha256', $h1, true) . $scramble, true);
    }

    /** What full authentication RSA-encrypts: (pw NUL) XOR scramble, the scramble repeated. */
    public static function rsaPlain(string $pw, string $scramble): string
    {
        $p = $pw . "\0";
        $n = \strlen($p);
        $k = \strlen($scramble);
        $o = '';
        for ($i = 0; $i < $n; $i++) { $o .= \chr(\ord($p[$i]) ^ \ord($scramble[$i % $k])); }
        return $o;
    }

    // ── packet layer ────────────────────────────────────────────────────────

    /** Top the buffer up to $n unread bytes. Compacts once, not per fread. */
    private function fill(int $n): void
    {
        if ($this->pos > 0) {
            $this->buf = \substr($this->buf, $this->pos);
            $this->pos = 0;
        }
        // one packet gets 2 x the read timeout plus a 64 KiB/s floor of sustained progress: each fread() is bounded,
        // a 1-byte drip is not, and a big row on a slow link still arrives
        // nothing of the next message buffered: this wait is for its START (the server is working), at EVERY message boundary of a
        // response, not only the first one (notices, slow rows, multi-result CALLs, multi-statement exec)
        if ($this->buf === '' && !$this->midPacket) { $this->awaiting = true; }
        // ⚠ php's ATTR_TIMEOUT is a CONNECT timeout. The idle wait for the START of a response (the server is running the
        // statement) is therefore unbounded unless the program set ATTR_TIMEOUT itself (superset); a packet that has begun
        // arriving, and connect/auth, are always bounded.
        $idle = $this->awaiting && !$this->connecting && !$this->explicitTimeout && $this->drainDl === 0.0;
        $dl = $this->timeout > 0.0 && !$idle ? \__mc_monotonic_f() + 2.0 * $this->timeout + (float) $n / 65536.0 : 0.0;
        if ($this->drainDl > 0.0) { $dl = $this->drainDl; }
        while (\strlen($this->buf) < $n) {
            $c = \fread($this->s, 65536);
            if ($c === false || $c === '') {
                $eof = \feof($this->s);
                if ($eof) { $this->peerGone = true; }
                if (!$eof && $idle) { continue; }
                // mysqlnd (measured): EOF, a reset and a read timeout all read as 2006, never 2013
                throw $this->ioFail(2006, 'MySQL server has gone away');
            }
            $this->buf .= $c;
            $this->awaiting = false;
            if ($idle) {
                $idle = false;
                $dl = $this->timeout > 0.0 ? \__mc_monotonic_f() + 2.0 * $this->timeout + (float) $n / 65536.0 : 0.0;
                if ($this->drainDl > 0.0) { $dl = $this->drainDl; }
            }
            if (\strlen($this->buf) < $n) {
                $now = \__mc_monotonic_f();
                if (($dl > 0.0 && $now >= $dl) || ($this->connecting && $this->authDeadline > 0.0 && $now >= $this->authDeadline)) {
                    throw $this->ioFail(2006, 'MySQL server has gone away');
                }
            }
        }
    }

    /**
     * One logical packet: frames of up to 0xFFFFFF bytes joined while a frame is
     * full-size. The sequence id of every frame is checked, and the declared
     * length is checked against $cap BEFORE the body is read.
     */
    private function packet(int $cap): string
    {
        $out = '';
        while (true) {
            $this->midPacket = $out !== '';
            if (\strlen($this->buf) - $this->pos < 4) { $this->fill(4); }
            $p = $this->pos;
            $len = \ord($this->buf[$p]) | (\ord($this->buf[$p + 1]) << 8) | (\ord($this->buf[$p + 2]) << 16);
            if (\ord($this->buf[$p + 3]) !== $this->seq) {
                // the server's own ERR (wait_timeout, shutdown) written while the connection idled carries the sequence of
                // the PREVIOUS exchange; it is the answer to the next command, as in mysqlnd (measured: 4031)
                $ok = false;
                if ($this->respStart && $len > 0) {
                    if (\strlen($this->buf) - $p < 5) { $this->fill(5); $p = $this->pos; }
                    $ok = \ord($this->buf[$p + 4]) === 0xFF;
                    if ($ok) { $this->seq = \ord($this->buf[$p + 3]); }
                }
                if (!$ok) { throw $this->malformed('packets out of order'); }
            }
            $this->respStart = false;
            $this->seq = ($this->seq + 1) & 255;
            if (\strlen($out) + $len > $cap) { throw $this->malformed('packet too large'); }
            if (\strlen($this->buf) - $p < 4 + $len) {
                $this->fill(4 + $len);
                $p = 0;
            }
            $out .= \substr($this->buf, $p + 4, $len);
            $this->pos = $p + 4 + $len;
            if ($len < 0xFFFFFF) { $this->midPacket = false; return $out; }
        }
    }

    private function sendPacket(string $payload): void
    {
        if ($this->dead) { throw $this->ioFail(2006, 'MySQL server has gone away'); }
        $n = \strlen($payload);
        $o = 0;
        do {
            $c = $n - $o > 0xFFFFFF ? 0xFFFFFF : $n - $o;
            $m = self::putLe($c, 3) . \chr($this->seq) . \substr($payload, $o, $c);
            $this->seq = ($this->seq + 1) & 255;
            $o += $c;
            while ($m !== '') {
                $w = \fwrite($this->s, $m);
                if ($w === false || $w === 0) {
                    $this->peerGone = true;
                    throw $this->lastWords() ?? $this->ioFail(2006, 'MySQL server has gone away');
                }
                $m = $w >= \strlen($m) ? '' : \substr($m, $w);
            }
            // a payload that is an exact multiple of 0xFFFFFF ends with an empty frame
        } while ($o < $n || ($c === 0xFFFFFF && $o === $n));
    }

    /**
     * A write the peer refused: the server may have answered and closed before reading the rest (a packet above
     * max_allowed_packet: ERR 1153, then close). Whether the write fails first is a race the kernel decides (Linux
     * resets mid-write, macOS takes the bytes); the server's own ERR, when it sent one, is the answer either way.
     * Bounded: one second, whatever is already queued.
     */
    private function lastWords(): ?PDOException
    {
        if ($this->connecting) { return null; }
        \stream_set_timeout($this->s, 1);
        $b = \substr($this->buf, $this->pos);
        while (\strlen($b) < 4 || \strlen($b) < 4 + (\ord($b[0]) | (\ord($b[1]) << 8) | (\ord($b[2]) << 16))) {
            $c = \fread($this->s, 65536);
            if ($c === false || $c === '') { break; }
            $b .= $c;
        }
        if (\strlen($b) < 5 || \ord($b[4]) !== 0xFF) { return null; }
        $len = \ord($b[0]) | (\ord($b[1]) << 8) | (\ord($b[2]) << 16);
        if (\strlen($b) < 4 + $len) { return null; }
        $this->closeSock();
        return __McPdoMy::errFrom($this->parseErr(\substr($b, 4, $len)));
    }

    /** Marks the connection dead and releases the fd (once). */
    private function closeSock(): void
    {
        // a command abandoned mid-flight (cancel, timeout) keeps running server-side: ask the server to stop it
        if (!$this->closed && $this->inflight && !$this->noCancel && !$this->connecting && !$this->peerGone && $this->connId > 0) {
            $this->cancelRequest();
        }
        $this->dead = true;
        $this->openCursor = null;
        if ($this->closed) { return; }
        $this->closed = true;
        unset(self::$pwStore[$this->kPwId]);
        \fclose($this->s);
    }

    /**
     * Best effort, as pdo_pgsql's CancelRequest: a second connection with the same credentials runs KILL QUERY <connId>.
     * Bounded (~2 s connect + auth, the reply bounded by what is left), every error swallowed.
     * A cancelled task re-raises at every park, and this connection parks: hold the cancellation back for the exchange.
     * Named by string, not spelled, so a synchronous PDO program does not pull the scheduler in (see \Runtime\AsyncHook).
     */
    private function cancelRequest(): void
    {
        $this->inflight = false;
        if (\Runtime\AsyncHook::active() && \function_exists('Async\\shield')) {
            $shield = 'Async\\shield';
            $shield(function (): void { $this->sendKill(); });
            return;
        }
        $this->sendKill();
    }

    private function sendKill(): void
    {
        $w = null;
        try {
            $t0 = \__mc_monotonic_f();
            $w = new self($this->host, $this->port, 1.0);
            $w->explicitTimeout = true;
            $w->noCancel = true;
            $w->handshake();
            $w->auth($this->kUser, self::$pwStore[$this->kPwId] ?? '', '', $this->kCs, false, false, false, $this->kTls);
            $left = 2.0 - (\__mc_monotonic_f() - $t0);
            $w->setTimeout($left > 0.2 ? $left / 2.0 : 0.1);
            $w->query('KILL QUERY ' . (string) $this->connId);
        } catch (\Throwable $e) {
        }
        if ($w !== null) { $w->close(); }
    }

    private function ioFail(int $no, string $m): PDOException
    {
        $this->closeSock();
        if ($this->connecting) { return __McPdo::connectFail('HY000', $no, $m); }
        return __McPdo::fail('HY000', $no, true, $m, true);
    }

    /** A packet that is not what the protocol allows: the stream cannot be trusted any more. */
    private function malformed(string $what): PDOException
    {
        return $this->ioFail(2027, 'Malformed packet (' . $what . ')');
    }

    // ── connect + authenticate ──────────────────────────────────────────────

    /** The charset number the handshake response carries; null when the name is unknown. */
    public static function charsetNo(string $name, string $serverVersion): ?int
    {
        $n = \strtolower($name);
        if ($n === 'utf8mb4') {
            // mysqlnd: utf8mb4_0900_ai_ci on 8.x, utf8mb4_general_ci before (and on MariaDB)
            return (int) $serverVersion >= 8 && \strpos($serverVersion, 'MariaDB') === false ? 255 : 45;
        }
        if ($n === 'utf8' || $n === 'utf8mb3') { return 33; }
        if ($n === 'latin1') { return 8; }
        if ($n === 'ascii') { return 11; }
        if ($n === 'binary') { return 63; }
        if ($n === 'latin2') { return 9; }
        if ($n === 'cp1250') { return 26; }
        if ($n === 'cp1251') { return 51; }
        if ($n === 'koi8r') { return 7; }
        if ($n === 'koi8u') { return 22; }
        if ($n === 'greek') { return 25; }
        if ($n === 'hebrew') { return 16; }
        return null;
    }

    /** Reads and validates the server's initial handshake (protocol 10). */
    public function handshake(): void
    {
        $p = $this->packet(self::CTRL_CAP);
        $n = \strlen($p);
        if ($n < 1) { throw $this->malformed('empty handshake'); }
        if (\ord($p[0]) === 0xFF) { throw $this->errPacket($p); }
        if (\ord($p[0]) !== 10) { throw $this->malformed('protocol version ' . (string) \ord($p[0])); }
        $z = \strpos($p, "\0", 1);
        if ($z === false) { throw $this->malformed('server version'); }
        $this->serverVersion = \substr($p, 1, $z - 1);
        $o = $z + 1;
        // connection id 4, scramble part 1 (8) + filler, capabilities (low 2), charset, status, capabilities (high 2), auth data length, reserved 10
        if ($o + 4 + 8 + 1 + 2 + 1 + 2 + 2 + 1 + 10 > $n) { throw $this->malformed('truncated handshake'); }
        $this->connId = self::le32($p, $o);
        $s1 = \substr($p, $o + 4, 8);
        $o += 13;
        $caps = self::le16($p, $o);
        $this->status = self::le16($p, $o + 3);
        $caps |= self::le16($p, $o + 5) << 16;
        $alen = \ord($p[$o + 7]);
        $o += 8 + 10;
        $this->srvCaps = $caps;
        if (($caps & self::CLIENT_PROTOCOL_41) === 0) { throw $this->malformed('server does not speak protocol 4.1'); }
        if (($caps & self::CLIENT_SECURE_CONNECTION) === 0) { throw $this->malformed('server lacks secure connection'); }
        // the scramble is 20 bytes: 8 + 12, the 13th byte of part 2 being a NUL
        $l2 = $alen - 8;
        if ($l2 < 13 || $l2 > 64 || $o + $l2 > $n) { throw $this->malformed('scramble shorter than 20 bytes'); }
        $this->scramble = $s1 . \substr($p, $o, 12);
        if ($p[$o + 12] !== "\0" && $l2 === 13) { throw $this->malformed('scramble not terminated'); }
        $o += $l2;
        if (($caps & self::CLIENT_PLUGIN_AUTH) !== 0) {
            $z = \strpos($p, "\0", $o);
            $this->plugin = $z === false ? \substr($p, $o) : \substr($p, $o, $z - $o);
        } else {
            $this->plugin = 'mysql_native_password';
        }
    }

    /** Only the two plugins this driver can answer; anything else fails BEFORE a response is sent. */
    private function pluginResponse(string $plugin, string $pw, string $scramble): string
    {
        if ($plugin === 'mysql_native_password') { return self::nativeAuth($pw, $scramble); }
        if ($plugin === 'caching_sha2_password') { return self::sha2Auth($pw, $scramble); }
        // sha256_password (mysqlnd): inside TLS the password in the clear + NUL; on a plain socket ask for the RSA key (0x01)
        if ($plugin === 'sha256_password') { return $pw === '' ? "\0" : ($this->tls ? $pw . "\0" : "\x01"); }
        $this->closeSock();
        throw __McPdo::connectFail('HY000', 2054, 'The server requested authentication method unknown to the client [' . $plugin . ']');
    }

    /**
     * SSLRequest (the first 32 bytes of a HandshakeResponse41, sequence 1), then the TLS handshake; the real response
     * follows over TLS as sequence 2. A server says nothing between its handshake packet and our response, so a byte
     * buffered or arriving there is an injection (CVE-2021-23222 class) and ends the attempt.
     */
    private function startTls(__McPdoTlsOpts $o, int $caps, int $charsetNo): void
    {
        if (\strlen($this->buf) > $this->pos || __McPdoTls::pendingPlain($this->s)) { throw $this->tlsFail(); }
        $this->sendPacket(self::putLe($caps, 4) . self::putLe(0x40000000, 4) . \chr($charsetNo) . \str_repeat("\0", 23));
        if (__McPdoTls::pendingPlain($this->s)) { throw $this->tlsFail(); }
        $r = __McPdoTls::start($this->s, $o, $this->timeout > 0.0 ? 2.0 * $this->timeout : 0.0);
        if ($r->stage !== '') { throw $this->tlsFail(); }
        $this->tls = true;
    }

    /** mysqlnd words every TLS failure the same. */
    private function tlsFail(): PDOException
    {
        $this->closeSock();
        return __McPdo::connectFail('HY000', 2002, 'Cannot connect to MySQL using SSL');
    }

    /**
     * HandshakeResponse41 and the rest of the authentication exchange, up to the OK packet.
     */
    public function auth(string $user, ?string $pw, string $db, int $charsetNo, bool $foundRows, bool $multi, bool $ignoreSpace, ?__McPdoTlsOpts $tlsOpts = null): void
    {
        $pw = $pw === null ? '' : $pw;
        $this->kUser = $user;
        if ($this->kPwId === 0) { self::$pwSeq = self::$pwSeq + 1; $this->kPwId = self::$pwSeq; }
        self::$pwStore[$this->kPwId] = $pw;
        $this->kCs = $charsetNo;
        $this->kTls = $tlsOpts;
        if (\strpos($user, "\0") !== false || \strpos($db, "\0") !== false) {
            $this->closeSock();
            throw __McPdo::connectFail('HY000', 2000, 'invalid NUL byte in user or database name');
        }
        $caps = self::CLIENT_LONG_PASSWORD | self::CLIENT_LONG_FLAG | self::CLIENT_PROTOCOL_41 | self::CLIENT_TRANSACTIONS
            | self::CLIENT_SECURE_CONNECTION | self::CLIENT_MULTI_RESULTS | self::CLIENT_PS_MULTI_RESULTS
            | self::CLIENT_PLUGIN_AUTH | self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;
        if ($db !== '') { $caps |= self::CLIENT_CONNECT_WITH_DB; }
        if ($foundRows) { $caps |= self::CLIENT_FOUND_ROWS; }
        if ($multi) { $caps |= self::CLIENT_MULTI_STATEMENTS; }
        if ($ignoreSpace) { $caps |= self::CLIENT_IGNORE_SPACE; }
        if ($tlsOpts !== null) {
            // mysqlnd (measured): a server without CLIENT_SSL answers a client that wants TLS with 2006
            if (($this->srvCaps & self::CLIENT_SSL) === 0) { throw $this->ioFail(2006, 'MySQL server has gone away'); }
            $caps |= self::CLIENT_SSL;
        }
        $caps &= $this->srvCaps | self::CLIENT_FOUND_ROWS | self::CLIENT_IGNORE_SPACE;
        $this->cliCaps = $caps;
        if ($tlsOpts !== null) { $this->startTls($tlsOpts, $caps, $charsetNo); }
        $auth = $this->pluginResponse($this->plugin, $pw, $this->scramble);
        $r = self::putLe($caps, 4) . self::putLe(0x40000000, 4) . \chr($charsetNo) . \str_repeat("\0", 23) . $user . "\0";
        if (($caps & self::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA) !== 0) {
            $r .= self::lenencInt(\strlen($auth)) . $auth;
        } else {
            $r .= \chr(\strlen($auth)) . $auth;
        }
        if (($caps & self::CLIENT_CONNECT_WITH_DB) !== 0) { $r .= $db . "\0"; }
        if (($caps & self::CLIENT_PLUGIN_AUTH) !== 0) { $r .= $this->plugin . "\0"; }
        $this->sendPacket($r);

        $plugin = $this->plugin;
        $scramble = $this->scramble;
        $switches = 0;
        $fast = false;
        $full = false;
        $steps = 0;
        while (true) {
            // ponytail: 16 is far beyond any real exchange (switch, more-data, full-auth); the cap only stops a ping-pong
            if (++$steps > 16) { throw $this->malformed('too many authentication packets'); }
            if ($this->authDeadline > 0.0 && \__mc_monotonic_f() >= $this->authDeadline) {
                throw $this->ioFail(2006, 'MySQL server has gone away');
            }
            $p = $this->packet(self::CTRL_CAP);
            if (\strlen($p) < 1) { throw $this->malformed('empty authentication packet'); }
            $t = \ord($p[0]);
            if ($t === 0x00) {
                $this->parseOk($p, new __McPdoMyResult());
                $this->connecting = false;
                return;
            }
            if ($t === 0xFF) { throw $this->errPacket($p); }
            if ($t === 0xFE) {
                if (++$switches > 2) { throw $this->malformed('too many authentication switches'); }
                if (\strlen($p) === 1) {
                    $this->closeSock();
                    throw __McPdo::connectFail('HY000', 2059, "Authentication plugin 'mysql_old_password' cannot be loaded");
                }
                $z = \strpos($p, "\0", 1);
                if ($z === false) { throw $this->malformed('authentication switch'); }
                $plugin = \substr($p, 1, $z - 1);
                if ($plugin !== 'mysql_native_password' && $plugin !== 'caching_sha2_password' && $plugin !== 'sha256_password') {
                    // mysqlnd (measured against MariaDB ed25519): the plugin is refused before its data is looked at
                    $this->pluginResponse($plugin, $pw, '');
                }
                $data = \substr($p, $z + 1);
                if ($data !== '' && $data[\strlen($data) - 1] === "\0") { $data = \substr($data, 0, \strlen($data) - 1); }
                if (\strlen($data) !== 20) { throw $this->malformed('scramble shorter than 20 bytes'); }
                $scramble = $data;
                $fast = false;
                $full = false;
                $this->sendPacket($this->pluginResponse($plugin, $pw, $scramble));
                continue;
            }
            if ($t === 0x01 && $plugin === 'caching_sha2_password' && \strlen($p) >= 2) {
                $m = \ord($p[1]);
                if ($m === 3 && !$fast && !$full) { $fast = true; continue; }
                if ($m === 4 && !$fast && !$full) {
                    $full = true;
                    $this->fullAuth($pw, $scramble);
                    continue;
                }
            }
            if ($t === 0x01 && $plugin === 'sha256_password' && !$full && !$this->tls) {
                $full = true;
                $this->sendEncrypted($p, $pw, $scramble);
                continue;
            }
            throw $this->malformed('unexpected authentication packet');
        }
    }

    /**
     * caching_sha2_password perform_full_authentication over a plain socket: ask for
     * the server's RSA key and send the password RSA-OAEP encrypted. Inside TLS the password is sent
     * as is (NUL terminated), which is what mysqlnd does.
     */
    private function fullAuth(string $pw, string $scramble): void
    {
        if ($this->tls) {
            $this->sendPacket($pw . "\0");
            return;
        }
        $this->sendPacket("\x02");
        $this->sendEncrypted($this->packet(self::CTRL_CAP), $pw, $scramble);
    }

    /** $k is the server's reply to a key request: 0x01 + PEM. Sends the password RSA-OAEP encrypted. */
    private function sendEncrypted(string $k, string $pw, string $scramble): void
    {
        // a 16384-bit key is ~2.4 KiB of PEM: 8 KiB is generous, anything bigger is hostile
        if (\strlen($k) < 27 || \strlen($k) > 8192 || \ord($k[0]) !== 1 || \strncmp(\substr($k, 1), '-----BEGIN PUBLIC KEY-----', 26) !== 0) {
            throw $this->malformed('server public key');
        }
        $enc = '';
        $ok = false;
        try {
            // 4 = OPENSSL_PKCS1_OAEP_PADDING; an unparsable key is a ValueError
            $ok = \openssl_public_encrypt(self::rsaPlain($pw, $scramble), $enc, \substr($k, 1), 4);
        } catch (\Throwable $t) {
        }
        if (!$ok || $enc === '') {
            $this->closeSock();
            throw __McPdo::connectFail('HY000', 2059, 'caching_sha2_password: could not encrypt the password with the server public key');
        }
        $this->sendPacket($enc);
    }

    // ── packets of the command phase ────────────────────────────────────────

    /** Decodes an ERR packet into a connect failure (HY000, php's shape for every connect error). */
    private function errPacket(string $p): PDOException
    {
        $e = $this->parseErr($p);
        $this->closeSock();
        return __McPdo::connectFail('HY000', $e->no, $e->msg);
    }

    public function parseErr(string $p): __McPdoMyErr
    {
        $e = new __McPdoMyErr();
        $n = \strlen($p);
        if ($n < 3) { return $e; }
        $e->no = self::le16($p, 1);
        $o = 3;
        if ($n >= 9 && $p[3] === '#') {
            $st = \substr($p, 4, 5);
            if (\preg_match('/^[0-9A-Z]{5}$/', $st) === 1) { $e->state = $st; }
            $o = 9;
        }
        $e->msg = \substr($p, $o);
        return $e;
    }

    private function parseOk(string $p, __McPdoMyResult $r): void
    {
        $n = \strlen($p);
        $a = self::lenencAt($p, 1, \PHP_INT_MAX);
        if ($a < 0) { throw $this->malformed('OK packet'); }
        $o = 1 + self::lenencSize($p, 1);
        $i = self::lenencAt($p, $o, \PHP_INT_MAX);
        if ($i < 0) { throw $this->malformed('OK packet'); }
        $o += self::lenencSize($p, $o);
        if ($o + 4 > $n) { throw $this->malformed('OK packet'); }
        $r->affected = $a;
        $r->insertId = $i;
        $r->status = self::le16($p, $o);
        $r->warnings = self::le16($p, $o + 2);
        $this->status = $r->status;
    }

    /** A length-encoded string at $o; sets $this->lp past it. A bad length is a malformed packet. */
    private function lstr(string $b, int $o): string
    {
        $l = self::lenencAt($b, $o);
        if ($l < 0) { throw $this->malformed('length-encoded string'); }
        $o += self::lenencSize($b, $o);
        if ($l > \strlen($b) - $o) { throw $this->malformed('string overruns packet'); }
        $this->lp = $o + $l;
        return \substr($b, $o, $l);
    }

    private function parseCol(string $b, __McPdoMyResult $r): void
    {
        $this->lstr($b, 0);                   // catalog
        $this->lstr($b, $this->lp);           // schema
        $tbl = $this->lstr($b, $this->lp);    // table (alias)
        $this->lstr($b, $this->lp);           // org_table
        $name = $this->lstr($b, $this->lp);
        $this->lstr($b, $this->lp);           // org_name
        $o = $this->lp;
        if ($o + 13 > \strlen($b) || \ord($b[$o]) !== 0x0c) { throw $this->malformed('column definition'); }
        $r->names[] = $name;
        $r->tables[] = $tbl;
        $r->lens[] = self::le32($b, $o + 3);
        $r->types[] = \ord($b[$o + 7]);
        $r->flags[] = self::le16($b, $o + 8);
        $r->decs[] = \ord($b[$o + 10]);
    }

    /**
     * A text row: one length-encoded string (or the NULL marker) per column.
     * Every length is checked against the packet: a hostile server must not make
     * substr() read, or us allocate, past it.
     *
     * @return array<int,?string>
     */
    private function parseRow(string $b, int $ncols): array
    {
        $bl = \strlen($b);
        $o = 0;
        /** @var array<int,?string> */
        $row = [];
        for ($i = 0; $i < $ncols; $i++) {
            if ($o >= $bl) { throw $this->malformed('row has too few columns'); }
            $c = \ord($b[$o]);
            if ($c === 251) { $row[] = null; $o++; continue; }
            if ($c < 251) {
                $l = $c;
                $o++;
            } else {
                $l = self::lenencAt($b, $o);
                if ($l < 0) { throw $this->malformed('row length'); }
                $o += self::lenencSize($b, $o);
            }
            if ($l > $bl - $o) { throw $this->malformed('row value overruns packet'); }
            $row[] = \substr($b, $o, $l);
            $o += $l;
        }
        if ($o !== $bl) { throw $this->malformed('row has trailing bytes'); }
        return $row;
    }

    /**
     * A binary-protocol row (COM_STMT_EXECUTE): 0x00, a NULL bitmap with a 2-bit offset, then the non-NULL values in
     * their wire form. Integers and floats stay as their raw little-endian bytes (decoded by columnValue); YEAR, DATE,
     * DATETIME, TIMESTAMP and TIME are formatted here (mysqlnd: `YYYY-MM-DD HH:MM:SS[.ffffff]`, fraction digits = the
     * column's decimals); everything else is a length-encoded string. Every size is checked against the packet before it
     * is read, and a length byte outside what the protocol defines ends the connection.
     *
     * @return array<int,?string>
     */
    private function parseBinRow(string $b, __McPdoMyResult $r): array
    {
        $nc = \count($r->types);
        $bl = \strlen($b);
        $nb = ($nc + 9) >> 3;
        if ($bl < 1 + $nb) { throw $this->malformed('binary row: NULL bitmap'); }
        if (\ord($b[0]) !== 0) { throw $this->malformed('binary row header'); }
        $o = 1 + $nb;
        /** @var array<int,?string> */
        $row = [];
        for ($i = 0; $i < $nc; $i++) {
            $bit = $i + 2;
            if (((\ord($b[1 + ($bit >> 3)]) >> ($bit & 7)) & 1) !== 0) { $row[] = null; continue; }
            $t = $r->types[$i];
            $sz = 0;
            if ($t === 1) { $sz = 1; }
            elseif ($t === 2 || $t === 13) { $sz = 2; }
            elseif ($t === 3 || $t === 9 || $t === 4) { $sz = 4; }
            elseif ($t === 8 || $t === 5) { $sz = 8; }
            if ($sz > 0) {
                if ($sz > $bl - $o) { throw $this->malformed('binary row overruns packet'); }
                $v = \substr($b, $o, $sz);
                $o += $sz;
                // YEAR: mysqlnd hands the number over as text
                $row[] = $t === 13 ? \str_pad((string) self::le16($v, 0), 4, '0', \STR_PAD_LEFT) : $v;
                continue;
            }
            if ($t === 6) { $row[] = null; continue; }
            if ($t === 7 || $t === 10 || $t === 12 || $t === 11) {
                if ($o >= $bl) { throw $this->malformed('binary row overruns packet'); }
                $n = \ord($b[$o]);
                $o++;
                if ($t === 11 ? ($n !== 0 && $n !== 8 && $n !== 12) : ($n !== 0 && $n !== 4 && $n !== 7 && $n !== 11)) {
                    throw $this->malformed('binary row: date/time length ' . (string) $n);
                }
                if ($n > $bl - $o) { throw $this->malformed('binary row overruns packet'); }
                $row[] = $t === 11 ? self::fmtTime($b, $o, $n, $r->decs[$i]) : self::fmtDate($b, $o, $n, $t === 10, $r->decs[$i]);
                $o += $n;
                continue;
            }
            $l = self::lenencAt($b, $o);
            if ($l < 0) { throw $this->malformed('binary row length'); }
            $o += self::lenencSize($b, $o);
            if ($l > $bl - $o) { throw $this->malformed('binary row value overruns packet'); }
            $row[] = \substr($b, $o, $l);
            $o += $l;
        }
        if ($o !== $bl) { throw $this->malformed('binary row has trailing bytes'); }
        return $row;
    }

    /** `.ffffff` cut to the column's decimals (mysqlnd: 1..6 digits, none otherwise). */
    private static function fmtFrac(int $micro, int $dec): string
    {
        if ($dec < 1 || $dec > 6) { return ''; }
        $d = $micro;
        for ($k = 6; $k > $dec; $k--) { $d = \intdiv($d, 10); }
        return '.' . \str_pad((string) $d, $dec, '0', \STR_PAD_LEFT);
    }

    private static function pad2(int $v): string { return $v < 10 ? '0' . (string) $v : (string) $v; }

    /** DATE / DATETIME / TIMESTAMP value of $n bytes (0, 4, 7 or 11) at $o; length 0 is the zero date. */
    private static function fmtDate(string $b, int $o, int $n, bool $dateOnly, int $dec): string
    {
        $y = $n >= 4 ? self::le16($b, $o) : 0;
        $s = \str_pad((string) $y, 4, '0', \STR_PAD_LEFT) . '-' . self::pad2($n >= 4 ? \ord($b[$o + 2]) : 0) . '-' . self::pad2($n >= 4 ? \ord($b[$o + 3]) : 0);
        if ($dateOnly) { return $s; }
        $s .= ' ' . self::pad2($n >= 7 ? \ord($b[$o + 4]) : 0) . ':' . self::pad2($n >= 7 ? \ord($b[$o + 5]) : 0) . ':' . self::pad2($n >= 7 ? \ord($b[$o + 6]) : 0);
        return $s . self::fmtFrac($n >= 11 ? self::le32($b, $o + 7) : 0, $dec);
    }

    /** TIME of $n bytes (0, 8 or 12): sign, days, h, m, s[, micro]; days fold into the hours. */
    private static function fmtTime(string $b, int $o, int $n, int $dec): string
    {
        $neg = $n >= 8 && \ord($b[$o]) !== 0;
        $h = $n >= 8 ? self::le32($b, $o + 1) * 24 + \ord($b[$o + 5]) : 0;
        $s = ($neg ? '-' : '') . self::pad2($h) . ':' . self::pad2($n >= 8 ? \ord($b[$o + 6]) : 0) . ':' . self::pad2($n >= 8 ? \ord($b[$o + 7]) : 0);
        return $s . self::fmtFrac($n >= 12 ? self::le32($b, $o + 8) : 0, $dec);
    }

    /** IEEE 754 binary64 from 8 little-endian bytes (no unpack(): see the file header). */
    public static function f64(string $b): float
    {
        $lo = self::le32($b, 0);
        $hi = self::le32($b, 4);
        $e = ($hi >> 20) & 0x7FF;
        $m = (($hi & 0xFFFFF) << 32) | $lo;
        $neg = ($hi & 0x80000000) !== 0;
        if ($e === 2047) { return $m === 0 ? ($neg ? -\INF : \INF) : \NAN; }
        $v = $e === 0 ? (float) $m * (2.0 ** -1074) : (float) ((1 << 52) | $m) * (2.0 ** ($e - 1075));
        return $neg ? -$v : $v;
    }

    /**
     * binary32 as mysqlnd reads it: a FLOAT column with fixed decimals prints `%.Nf`, otherwise php_gcvt with
     * FLT_DIG = 6 significant digits, and that text is parsed back to a double (so 0.1f is 0.1, not 0.10000000149).
     */
    public static function f32(string $b, int $dec): float
    {
        $x = self::le32($b, 0);
        $e = ($x >> 23) & 0xFF;
        $m = $x & 0x7FFFFF;
        $neg = ($x & 0x80000000) !== 0;
        if ($e === 255) { return $m === 0 ? ($neg ? -\INF : \INF) : \NAN; }
        $v = $e === 0 ? (float) $m * (2.0 ** -149) : (float) ((1 << 23) | $m) * (2.0 ** ($e - 150));
        if ($neg) { $v = -$v; }
        return (float) ($dec < 31 ? \sprintf('%.' . (string) $dec . 'f', $v) : \sprintf('%.5e', $v));
    }

    /** One result (OK, ERR or a result set) of the response to a command. */
    private function readResult(bool $bin = false, bool $stream = false): __McPdoMyResult
    {
        $r = new __McPdoMyResult();
        $p = $this->packet(self::CTRL_CAP);
        if (\strlen($p) < 1) { throw $this->malformed('empty response'); }
        $t = \ord($p[0]);
        if ($t === 0x00) {
            $this->parseOk($p, $r);
            return $r;
        }
        if ($t === 0xFF) {
            $r->err = $this->parseErr($p);
            return $r;
        }
        if ($t === 0xFB) {
            // LOAD DATA LOCAL INFILE: never requested (no CLIENT_LOCAL_FILES), so a server asking is hostile
            throw $this->malformed('unsolicited LOCAL INFILE request');
        }
        $nc = self::lenencAt($p, 0);
        if ($nc < 1 || $nc > self::MAX_COLUMNS || self::lenencSize($p, 0) !== \strlen($p)) {
            throw $this->malformed('column count');
        }
        for ($i = 0; $i < $nc; $i++) { $this->parseCol($this->packet(self::CTRL_CAP), $r); }
        $e = $this->packet(self::CTRL_CAP);
        if (\strlen($e) < 5 || \strlen($e) >= 9 || \ord($e[0]) !== 0xFE) { throw $this->malformed('missing EOF after column definitions'); }
        $r->isSet = true;
        $r->bin = $bin;
        if ($stream) {
            $r->stream = true;
            // the metadata EOF already carries SERVER_MORE_RESULTS_EXISTS, which is what mysqlnd's more_results() answers
            $r->status = self::le16($e, 3);
            $this->status = $r->status;
            return $r;
        }
        while (true) {
            $p = $this->packet(self::ROW_CAP);
            $bl = \strlen($p);
            if ($bl < 1) { throw $this->malformed('empty row'); }
            $t = \ord($p[0]);
            if ($t === 0xFE && $bl < 9) {
                if ($bl < 5) { throw $this->malformed('EOF packet'); }
                $r->warnings = self::le16($p, 1);
                $r->status = self::le16($p, 3);
                $this->status = $r->status;
                return $r;
            }
            if ($t === 0xFF) {
                // an error mid-result (killed, max_execution_time): the rows so far are dropped
                $r->err = $this->parseErr($p);
                $r->rows = [];
                return $r;
            }
            $r->rows[] = $bin ? $this->parseBinRow($p, $r) : $this->parseRow($p, $nc);
        }
    }

    /**
     * Runs one COM_QUERY and reads EVERY result it produces (a multi-statement
     * query is a chain). Never throws on a server error: the error lands in a
     * result's ->err and the stream is in sync. A protocol failure poisons the
     * connection and throws.
     */
    public function query(string $sql, bool $stream = false): __McPdoMyResult
    {
        return $this->cycle("\x03" . $sql, false, $stream);
    }

    /**
     * COM_STMT_EXECUTE of statement $id; $params is everything after the iteration count (NULL bitmap, bound flag, types,
     * values), empty for a statement without parameters. Same chain and same failure contract as query(), with binary rows.
     */
    public function execute(int $id, string $params, bool $stream = false): __McPdoMyResult
    {
        return $this->cycle("\x17" . self::putLe($id, 4) . "\0" . self::putLe(1, 4) . $params, true, $stream);
    }

    private function cycle(string $cmd, bool $bin, bool $stream = false): __McPdoMyResult
    {
        $this->enter();
        try {
            return $this->queryRun($cmd, $bin, $stream);
        } catch (\Throwable $t) {
            // a cancel/timeout/protocol failure inside a cycle leaves half a response on the wire: nobody may reuse it
            if ($this->inflight) { $this->closeSock(); }
            throw $t;
        } finally {
            $this->release();
        }
    }

    /**
     * Takes the wire for one cycle. Two fibers sharing a connection must not interleave packets.
     * ponytail: polling park (1 ms sleeper) bounded by ATTR_TIMEOUT, not a wait-queue; one connection per task via Async\Pool avoids the contention.
     * Without a scheduler a second entry is a re-entrancy bug, not contention.
     */
    private function enter(): void
    {
        // php: any command while an unbuffered result is open is 2014. Another fiber is not the owner: it waits for the cursor to end.
        if ($this->openCursor !== null && !$this->dead && (!\Runtime\AsyncHook::active() || \Fiber::getCurrent() === $this->curFiber)) {
            $e = new __McPdoMyErr();
            $e->no = 2014;
            $e->msg = ($this->status & 8) !== 0 || !$this->openCursor->entered
                ? 'Cannot execute queries while there are pending result sets. Consider unsetting the previous PDOStatement or calling PDOStatement::closeCursor()'
                : 'Cannot execute queries while other unbuffered queries are active.  Consider using PDOStatement::fetchAll().  Alternatively, if your code is only ever going to run against mysql, you may enable query buffering by setting the Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY attribute.';
            throw __McPdoMy::errFrom($e);
        }
        if ($this->busy || $this->openCursor !== null) {
            if (!\Runtime\AsyncHook::active()) { throw __McPdo::fail('HY000', 0, false, 'connection busy', true); }
            $sleeper = \Runtime\AsyncHook::sleeper();
            if ($sleeper === null) { throw __McPdo::fail('HY000', 0, false, 'connection busy', true); }
            // php: a timeout of 0 is unlimited
            $deadline = $this->timeout > 0.0 ? \__mc_monotonic_f() + $this->timeout : 0.0;
            while (($this->busy || $this->openCursor !== null) && !$this->dead) {
                if ($deadline > 0.0 && \__mc_monotonic_f() >= $deadline) {
                    throw __McPdo::fail('HY000', 0, false, 'timeout expired waiting for the connection', true);
                }
                $sleeper(0.001);
            }
        }
        $this->busy = true;
    }

    private function queryRun(string $cmd, bool $bin, bool $stream = false): __McPdoMyResult
    {
        if ($this->dead) { throw $this->ioFail(2006, 'MySQL server has gone away'); }
        $this->drainOwed();
        $this->inflight = true;
        $this->insertId = 0;
        $this->seq = 0;
        $this->sendPacket($cmd);
        $this->awaiting = true;
        $this->respStart = true;
        return $this->readChain($bin, $stream);
    }

    /**
     * Reads results until the chain ends, or ($stream) until one result set's header: its rows stay on the wire, the
     * connection stays mid-protocol (inflight) and openCursor names the result until cursorRow() reaches its end.
     */
    private function readChain(bool $bin, bool $stream): __McPdoMyResult
    {
        $first = null;
        $last = null;
        $n = 0;
        while (true) {
            $r = $this->readResult($bin, $stream);
            if ($first === null) { $first = $r; } else { $last->next = $r; }
            $last = $r;
            if (!$r->isSet && $r->err === null) { $this->insertId = $r->insertId; }
            if ($r->stream) {
                $this->openCursor = $r;
                $this->curBin = $bin;
                $this->curFiber = \Runtime\AsyncHook::active() ? \Fiber::getCurrent() : null;
                return $first;
            }
            if ($r->err !== null || ($r->status & 8) === 0) { break; }
            // ponytail: bounds a server that keeps promising "more results"; no real batch comes near it
            if (++$n > 100000) { throw $this->malformed('too many result sets'); }
        }
        $this->inflight = false;
        return $first;
    }

    /**
     * The next row of the open streamed result $r, [] once it ended (done) or failed (->err). $skip reads and
     * frames the row without decoding it. When the server promises more results, the next chain is linked behind $r
     * here, so the connection is free again exactly when the last result ended. Any failure (protocol, cancellation,
     * timeout) poisons the connection: half a result is still on the wire and nobody may reuse it.
     *
     * @return array<int,?string> empty at the end of the result (a row has at least one column)
     */
    public function cursorRow(__McPdoMyResult $r, bool $skip = false): array
    {
        if ($r->done || $this->openCursor !== $r) { return []; }
        if ($this->dead) {
            $r->done = true;
            $this->openCursor = null;
            return [];
        }
        $this->reading = true;
        try {
            $this->awaiting = true;
            $p = $this->packet(self::ROW_CAP);
            $bl = \strlen($p);
            if ($bl < 1) { throw $this->malformed('empty row'); }
            $t = \ord($p[0]);
            if ($t === 0xFE && $bl < 9) {
                if ($bl < 5) { throw $this->malformed('EOF packet'); }
                $r->warnings = self::le16($p, 1);
                $r->status = self::le16($p, 3);
                $this->status = $r->status;
                $r->done = true;
                if (($r->status & 8) !== 0) {
                    $r->more = true;
                } else {
                    $this->openCursor = null;
                    $this->inflight = false;
                }
                return [];
            }
            if ($t === 0xFF) {
                $r->err = $this->parseErr($p);
                $r->done = true;
                $this->openCursor = null;
                $this->inflight = false;
                return [];
            }
            if ($skip) { return [null]; }
            return $r->bin ? $this->parseBinRow($p, $r) : $this->parseRow($p, \count($r->types));
        } catch (\Throwable $e) {
            $r->done = true;
            $this->openCursor = null;
            $this->closeSock();
            throw $e;
        } finally {
            $this->reading = false;
        }
    }

    /** Reads the results behind a finished streamed result $r (see ->more) and links them to it. */
    public function cursorNext(__McPdoMyResult $r): void
    {
        if (!$r->more || $this->openCursor !== $r) { return; }
        $r->more = false;
        $this->openCursor = null;
        $this->reading = true;
        try {
            $this->awaiting = true;
            $r->next = $this->readChain($this->curBin, true);
        } catch (\Throwable $e) {
            $this->closeSock();
            throw $e;
        } finally {
            $this->reading = false;
        }
    }

    /**
     * Reads the open cursor (and every result chained behind it) to its end and drops the rows. Draining a huge result
     * costs a full transfer, so it is bounded by the connection timeout: past it the connection is closed instead
     * (the server stops the statement), never left mid-result.
     */
    public function drainCursor(): void
    {
        if ($this->openCursor === null) { return; }
        if ($this->reading) {
            // another fiber is parked inside a read of this very result: two readers would shred the stream
            $this->openCursor = null;
            $this->closeSock();
            return;
        }
        $this->startDrain();
        try {
            while ($this->openCursor !== null) {
                $c = $this->openCursor;
                if ($c->done) {
                    $this->cursorNext($c);
                    continue;
                }
                if (\count($this->cursorRow($c, true)) === 0) { continue; }
                if (\__mc_monotonic_f() >= $this->drainDl) {
                    $this->openCursor = null;
                    $this->closeSock();
                    return;
                }
            }
        } catch (PDOException $e) {
            // the server went quiet (or away) while we drained: the connection is closed, never left mid-result, and closing never hangs
            $this->openCursor = null;
            $this->closeSock();
            // a quiet or vanished server (2006) ends the drain silently; a protocol violation is still the caller's to hear about
            if ($e->driverCode !== 2006) { throw $e; }
        } finally {
            $this->endDrain();
        }
    }

    /**
     * COM_STMT_PREPARE. The parameter and column definitions that follow PREPARE_OK are validated and dropped: the result
     * set's own definitions arrive again with every execute, and those are the ones a row is decoded against. A server ERR
     * lands in ->err (the stream is in sync); a protocol failure poisons the connection and throws.
     */
    public function prepare(string $sql): __McPdoMyPrep
    {
        $this->enter();
        try {
            if ($this->dead) { throw $this->ioFail(2006, 'MySQL server has gone away'); }
            $this->drainOwed();
            $this->inflight = true;
            $this->insertId = 0;
            $this->seq = 0;
            $this->sendPacket("\x16" . $sql);
            $this->awaiting = true;
            $this->respStart = true;
            $p = $this->packet(self::CTRL_CAP);
            $q = new __McPdoMyPrep();
            if (\strlen($p) < 1) { throw $this->malformed('empty response'); }
            if (\ord($p[0]) === 0xFF) {
                $q->err = $this->parseErr($p);
                $this->inflight = false;
                return $q;
            }
            if (\ord($p[0]) !== 0 || \strlen($p) !== 12) { throw $this->malformed('PREPARE_OK'); }
            $q->id = self::le32($p, 1);
            $q->cols = self::le16($p, 5);
            $q->params = self::le16($p, 7);
            $q->warnings = self::le16($p, 10);
            if ($q->cols > self::MAX_COLUMNS || $q->params > self::MAX_PARAMS) { throw $this->malformed('PREPARE_OK counts'); }
            foreach ([$q->params, $q->cols] as $cnt) {
                if ($cnt === 0) { continue; }
                for ($i = 0; $i < $cnt; $i++) { $this->parseCol($this->packet(self::CTRL_CAP), new __McPdoMyResult()); }
                $e = $this->packet(self::CTRL_CAP);
                if (\strlen($e) < 5 || \strlen($e) >= 9 || \ord($e[0]) !== 0xFE) { throw $this->malformed('missing EOF after definitions'); }
            }
            $this->inflight = false;
            return $q;
        } catch (\Throwable $t) {
            if ($this->inflight) { $this->closeSock(); }
            throw $t;
        } finally {
            $this->release();
        }
    }

    /** Ends a cycle: closes queued by destructors while the wire was held are sent now, then the wire is free. */
    private function release(): void
    {
        $this->inflight = $this->openCursor !== null;
        try {
            if (!$this->dead && \count($this->owed) > 0) { $this->drainOwed(); }
        } catch (PDOException $e) {
            // the write failed: closeSock() already ran
        } catch (\Throwable $t) {
            // a cancellation inside the write may have left half a COM_STMT_CLOSE on a live socket
            $this->closeSock();
            throw $t;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * COM_STMT_CLOSE has no reply, so it needs only a free wire: sent now when nobody holds it, else owed and sent
     * ahead of the next command. Closing on a dead connection is a no-op (the server dropped the statement with it).
     */
    public function closeStmt(int $id): void
    {
        if ($this->dead) { return; }
        $this->owed[] = $id;
        // a COM_STMT_CLOSE written into a half-read result would corrupt it: it waits for the next command
        if ($this->busy || $this->openCursor !== null) { return; }
        $this->busy = true;
        try {
            $this->drainOwed();
        } catch (PDOException $e) {
            // the write failed: closeSock() already ran, nothing is left to close
        } catch (\Throwable $t) {
            // a cancellation inside the write may have left half a COM_STMT_CLOSE on a live socket
            $this->closeSock();
            throw $t;
        } finally {
            $this->busy = false;
        }
    }

    private function drainOwed(): void
    {
        while (\count($this->owed) > 0) {
            $id = \array_pop($this->owed);
            $this->seq = 0;
            $this->sendPacket("\x19" . self::putLe($id, 4));
        }
    }

    /**
     * COM_STATISTICS: one packet holding the status text (no OK header), or an ERR.
     * The same cycle discipline as query().
     */
    public function statistics(): string
    {
        $this->enter();
        try {
            if ($this->dead) { throw $this->ioFail(2006, 'MySQL server has gone away'); }
            $this->inflight = true;
            $this->seq = 0;
            $this->sendPacket("\x09");
            $this->awaiting = true;
            $this->respStart = true;
            $p = $this->packet(self::CTRL_CAP);
            $this->inflight = false;
            if ($p !== '' && \ord($p[0]) === 0xFF) { throw __McPdoMy::errFrom($this->parseErr($p)); }
            return $p;
        } catch (\Throwable $t) {
            if ($this->inflight) { $this->closeSock(); }
            throw $t;
        } finally {
            $this->release();
        }
    }

    /** Bumped by every session reset: a statement id from an earlier generation names nothing (or somebody else's statement). */
    public int $gen = 0;

    /** COM_PING. False on any failure, never throws. */
    public function ping(): bool
    {
        try {
            return $this->cycle("\x0e", false)->err === null;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * COM_RESET_CONNECTION: rolls back, drops session variables, temporary tables and every prepared statement.
     * An unbuffered result still on the wire is drained first (bounded, see drainCursor()); the COM_STMT_CLOSEs
     * still owed name statements that no longer exist.
     */
    public function resetConnection(): ?__McPdoMyErr
    {
        $this->drainCursor();
        $this->owed = [];
        $this->gen = $this->gen + 1;
        return $this->cycle("\x1f", false)->err;
    }

    public function close(): void
    {
        try {
            if (!$this->dead && !$this->busy && !$this->connecting && $this->openCursor === null) {
                try { $this->seq = 0; $this->sendPacket("\x01"); } catch (PDOException $e) {}
            }
        } finally {
            $this->closeSock();
        }
    }
}

final class __McPdoMy implements __McPdoDrv
{
    public __McPdoMyWire $wire;
    /** ATTR_EMULATE_PREPARES: pdo_mysql's default is on; off prepares each statement on the server (binary protocol). */
    public bool $emulate = true;
    /** ATTR_AUTOCOMMIT as the program last set it (the server starts in autocommit mode). */
    public bool $autocommit = true;
    /** ATTR_FETCH_TABLE_NAMES: stored and read back; column names are not prefixed yet. */
    public bool $fetchTables = false;
    /** ATTR_DEFAULT_STR_PARAM = PARAM_STR_NATL. */
    public bool $natl = false;
    /** MYSQL_ATTR_USE_BUFFERED_QUERY = false: statements stream their rows from the socket. */
    public bool $unbuffered = false;

    public function __construct(__McPdoMyWire $wire)
    {
        $this->wire = $wire;
    }

    public function name(): string { return 'mysql'; }

    public function prepare(string $sql): __McPdoDrvStmt
    {
        $s = new __McPdoMyStmt($this, $sql, !$this->emulate);
        $s->open();
        return $s;
    }

    public static function errFrom(__McPdoMyErr $e): PDOException
    {
        return __McPdo::fail($e->state, $e->no, true, $e->msg, true);
    }

    /** Runs $sql, throwing the first server error of its results as php's PDOException. */
    public function run(string $sql): __McPdoMyResult
    {
        $r = $this->wire->query($sql);
        for ($n = $r; $n !== null; $n = $n->next) {
            if ($n->err !== null) { throw self::errFrom($n->err); }
        }
        return $r;
    }

    /** The FIRST statement's affected rows, as mysql_affected_rows() after a multi-statement query. */
    public function exec(string $sql): int
    {
        $r = $this->run($sql);
        return $r->isSet ? 0 : $r->affected;
    }

    public function begin(): void { $this->run('START TRANSACTION'); }
    public function commit(): void { $this->run('COMMIT'); }
    public function rollback(): void { $this->run('ROLLBACK'); }
    public function inTransaction(): bool { return ($this->wire->status & 1) !== 0; }

    /** MYSQL_ATTR_INIT_COMMAND: a session reset wipes what it set up, so it runs again. */
    public string $initCmd = '';

    /** The connection-time ATTR_AUTOCOMMIT and DSN charset: what a reset returns the session to. */
    public bool $baseAutocommit = true;
    public string $baseCharset = '';

    /** COM_RESET_CONNECTION, then the session is set up again as the constructor did: charset, ATTR_AUTOCOMMIT, init command. */
    private bool $baseEmulate = true;
    private bool $baseFetchTables = false;
    private bool $baseNatl = false;
    private bool $baseUnbuffered = false;
    private float $baseTimeout = 30.0;
    private bool $baseExplicit = false;

    public function markBase(): void
    {
        $this->baseEmulate = $this->emulate;
        $this->baseFetchTables = $this->fetchTables;
        $this->baseNatl = $this->natl;
        $this->baseUnbuffered = $this->unbuffered;
        $this->baseTimeout = $this->wire->timeoutValue();
        $this->baseExplicit = $this->wire->explicitTimeout;
    }

    public function reset(): void
    {
        $this->wire->assertCursorOwner();
        $e = $this->wire->resetConnection();
        if ($e !== null) { throw self::errFrom($e); }
        // the server's defaults apply after a reset; the handshake charset is lost with them
        if ($this->baseCharset !== '') {
            // `SET NAMES cs` alone picks the SERVER's default collation for cs, which is not always the one the handshake asked for: MySQL honours the
            // collation id of the handshake exactly, so name it; MariaDB maps the id onto its own default for the charset (measured: utf8mb4_uca1400_ai_ci
            // after a handshake with 45), which is what a plain SET NAMES gives back.
            $c = '';
            if (\strpos($this->wire->serverVersion, 'MariaDB') === false) {
                $coll = $this->run('SELECT COLLATION_NAME FROM information_schema.COLLATIONS WHERE ID = ' . (string) $this->wire->charsetId())->rows;
                $c = isset($coll[0][0]) ? (string) $coll[0][0] : '';
            }
            $this->run('SET NAMES ' . $this->baseCharset . ($c !== '' && \preg_match('/^[a-z0-9_]+$/', $c) === 1 ? ' COLLATE ' . $c : ''));
        }
        $this->autocommit = $this->baseAutocommit;
        if (!$this->baseAutocommit) { $this->run('SET autocommit=0'); }
        if ($this->initCmd !== '') { $this->run($this->initCmd); }
        $this->emulate = $this->baseEmulate;
        $this->fetchTables = $this->baseFetchTables;
        $this->natl = $this->baseNatl;
        $this->unbuffered = $this->baseUnbuffered;
        $this->wire->setTimeout($this->baseTimeout);
        $this->wire->explicitTimeout = $this->baseExplicit;
    }

    public function ping(): bool { return $this->wire->ping(); }

    /** The name is ignored, as in pdo_mysql. */
    public function lastInsertId(?string $name): string { return (string) $this->wire->insertId; }

    /**
     * mysqlnd's real_escape_string for utf8mb4 as pdo_mysql calls it: no UTF-8
     * validation (an invalid sequence is escaped byte by byte, and no byte of
     * one is special); with NO_BACKSLASH_ESCAPES — the server's status flag,
     * read off the latest OK/EOF packet — only the quote is doubled.
     * PARAM_LOB is `_binary'...'`.
     */
    public function quote(string $s, int $type): string|false
    {
        $q = ($this->wire->status & 512) !== 0
            ? \str_replace("'", "''", $s)
            : \strtr($s, ["\0" => '\0', "\n" => '\n', "\r" => '\r', '\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\x1a" => '\Z']);
        if (($type & 7) === PDO::PARAM_LOB) { return "_binary'" . $q . "'"; }
        // mysql_handle_quoter: NATL (or the default-string attribute) means N'..', an explicit CHAR flag cancels it
        $n = $this->natl || ($type & PDO::PARAM_STR_NATL) === PDO::PARAM_STR_NATL;
        if (($type & PDO::PARAM_STR_CHAR) === PDO::PARAM_STR_CHAR) { $n = false; }
        return ($n ? "N'" : "'") . $q . "'";
    }

    public function errorInfo(): array { return __McPdo::triple('', 0, false, '', false); }

    public function setAttr(int $attr, mixed $value): bool
    {
        if ($attr === PDO::ATTR_EMULATE_PREPARES) { $this->emulate = (bool) $value; return true; }
        if ($attr === PDO::ATTR_AUTOCOMMIT) {
            if (!\is_bool($value) && !\is_int($value)) {
                throw new TypeError('Attribute value must be of type bool for selected attribute, ' . \get_debug_type($value) . ' given');
            }
            $on = (bool) $value;
            $this->run($on ? 'SET autocommit=1' : 'SET autocommit=0');
            $this->autocommit = $on;
            return true;
        }
        // php: ATTR_TIMEOUT is connect-time only. Here a program that sets it also bounds the idle wait for a response (superset)
        if ($attr === PDO::ATTR_TIMEOUT) {
            $this->wire->setTimeout((float) $value);
            $this->wire->explicitTimeout = true;
            // php (measured): the call answers false and reads back as unsupported; the effect above is the superset
            return false;
        }
        if ($attr === PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) { $this->unbuffered = !(bool) $value; return true; }
        if ($attr === PDO::ATTR_FETCH_TABLE_NAMES) { $this->fetchTables = (bool) $value; return true; }
        // assume_national_character_set_strings: only the NATL type switches it on
        if ($attr === PDO::ATTR_DEFAULT_STR_PARAM) { $this->natl = (int) $value === PDO::PARAM_STR_NATL; return true; }
        // connect-time options (FOUND_ROWS, MULTI_STATEMENTS, INIT_COMMAND, IGNORE_SPACE, LOCAL_INFILE, COMPRESS, SSL_*, ATTR_PREFETCH)
        // are read by open(); once connected php answers false, and so does this
        return false;
    }

    public function getAttr(int $attr): mixed
    {
        if ($attr === PDO::ATTR_SERVER_VERSION) {
            $v = $this->wire->serverVersion;
            // mysqlnd strips the replication-compat prefix MariaDB puts ahead of its own version
            if (\strncmp($v, '5.5.5-', 6) === 0 && \strpos($v, 'MariaDB') !== false) { $v = \substr($v, 6); }
            return $v;
        }
        if ($attr === PDO::ATTR_CLIENT_VERSION) { return 'manticore-mysqlwire 4.1'; }
        if ($attr === PDO::ATTR_CONNECTION_STATUS) { return $this->wire->host . ' via TCP/IP'; }
        if ($attr === PDO::ATTR_EMULATE_PREPARES) { return $this->emulate; }
        if ($attr === PDO::ATTR_AUTOCOMMIT) { return $this->autocommit; }
        if ($attr === PDO::ATTR_SERVER_INFO) { return $this->wire->statistics(); }
        if ($attr === PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) { return !$this->unbuffered; }
        if ($attr === PDO::ATTR_FETCH_TABLE_NAMES) { return $this->fetchTables; }
        if ($attr === PDO::ATTR_DEFAULT_STR_PARAM) { return $this->natl ? PDO::PARAM_STR_NATL : PDO::PARAM_STR_CHAR; }
        // LOCAL INFILE is refused at connect (see open()), so the option can only read back as off
        if ($attr === PDO::MYSQL_ATTR_LOCAL_INFILE) { return false; }
        return null;
    }

    public function close(): void { $this->wire->close(); }

    public function __destruct() { $this->wire->close(); }
}

/**
 * One PDOStatement. Emulated (the default): execute() splices the quoted values
 * into the SQL at the byte offsets the scanner recorded and sends one COM_QUERY
 * with the whole result buffered; a repeated `:name` is one slot per occurrence,
 * bound by its distinct name. Native (ATTR_EMULATE_PREPARES=false): the statement
 * lives on the server (COM_STMT_PREPARE at prepare(), COM_STMT_EXECUTE with typed
 * parameters at execute(), COM_STMT_CLOSE when the object goes away), `:name`
 * is rewritten to `?`, and a repeated name is refused as pdo_mysql does.
 */
final class __McPdoMyStmt implements __McPdoDrvStmt, __McPdoDrvMulti
{
    private __McPdoMy $db;
    private string $sql;
    /** @var array<int,string> one entry per placeholder occurrence: `?` or `:name` */
    private array $names = [];
    /** @var array<int,int> */
    private array $pos = [];
    /** @var array<int,string> SQL literal by 0-based slot */
    private array $lit = [];
    /** @var array<int,bool> */
    private array $set = [];
    /** A value was bound to a slot the statement does not have. */
    private bool $over = false;
    private ?__McPdoMyResult $res = null;
    private int $idx = -1;
    private int $nDistinct = 0;
    private bool $native = false;
    /** The server holds a prepared statement for this object and COM_STMT_CLOSE has not been sent. */
    private bool $prepared = false;
    private int $stmtId = 0;
    private int $stmtGen = 0;
    /** Placeholders the SERVER counted in the rewritten text. */
    private int $serverParams = 0;
    /** A `:name` occurs twice: pdo_mysql cannot bind that natively, execute() refuses it. */
    private bool $repeated = false;
    private string $nsql = '';
    /** @var array<int,int> per slot: 0 unbound, 1 NULL, 2 integer, 3 double, 4 string */
    private array $pk = [];
    /** @var array<int,int> */
    private array $pi = [];
    /** @var array<int,string> the string, or the 8 bytes of a double */
    private array $ps = [];
    /** What rowCount() answers while no result is current (after a failed execute php still reports the previous one). */
    private int $lastRows = 0;
    /** The first result of the last execute(): the chain a streamed cursor of this statement belongs to. */
    private ?__McPdoMyResult $head = null;
    /** @var array<int,?string> the row step() last streamed */
    private array $curRow = [];

    public function __construct(__McPdoMy $db, string $sql, bool $native = false)
    {
        $this->db = $db;
        $this->native = $native;
        $sc = __McPdoSql::scan($sql, 'mysql');
        $this->sql = $sc->sql;
        $this->names = $sc->names;
        $this->pos = $sc->pos;
        /** @var array<string,bool> */
        $seen = [];
        foreach ($this->names as $n) {
            if ($n === '?') { $this->nDistinct++; }
            elseif (!isset($seen[$n])) { $seen[$n] = true; $this->nDistinct++; }
            else { $this->repeated = true; }
        }
        $this->nsql = $this->sql;
        if ($native && \count($this->names) > 0) {
            $q = '';
            $o = 0;
            foreach ($this->names as $i => $n) {
                $p = $this->pos[$i];
                $q .= \substr($this->sql, $o, $p - $o) . '?';
                $o = $p + \strlen($n);
            }
            $this->nsql = $q . \substr($this->sql, $o);
        }
    }

    /** Native statements are prepared on the server here, so a syntax error is raised by prepare() as in php. */
    public function open(): void
    {
        if (!$this->native) { return; }
        $p = $this->db->wire->prepare($this->nsql);
        if ($p->err !== null) { throw __McPdoMy::errFrom($p->err); }
        $this->stmtId = $p->id;
        $this->stmtGen = $this->db->wire->gen;
        $this->serverParams = $p->params;
        $this->prepared = true;
    }

    public function __destruct() { $this->close(); }

    private static function badParam(): PDOException
    {
        return __McPdo::fail('HY093', 0, false, '', false);
    }

    private function bindNative(int $idx, mixed $value, int $type): void
    {
        if ($idx < 1 || $idx > \count($this->names)) { throw self::badParam(); }
        $s = $idx - 1;
        $t = $type & 7;
        // pdo core and pdo_mysql (measured): NULL and PARAM_NULL are NULL; PARAM_STR makes a string of any scalar
        // (true is "1", false ""); otherwise the value keeps its own type: bool/int -> LONGLONG (PARAM_BOOL folds an int
        // to 0/1), float -> DOUBLE, string and stream contents -> VAR_STRING
        if ($value === null || $t === PDO::PARAM_NULL) { $this->pk[$s] = 1; return; }
        // ⚠ the scalar tests come first: is_resource() on a denormal float crashes (#331)
        if ($t !== PDO::PARAM_STR) {
            // a bool that stays a bool (PARAM_BOOL, PARAM_LOB) travels as TINY; MariaDB reports it back (MySQL widens it to LONGLONG)
            if (\is_bool($value)) { $this->pk[$s] = $t === PDO::PARAM_INT ? 2 : 5; $this->pi[$s] = $value ? 1 : 0; return; }
            if (\is_int($value)) {
                if ($t === PDO::PARAM_BOOL) { $this->pk[$s] = 5; $this->pi[$s] = $value !== 0 ? 1 : 0; return; }
                $this->pk[$s] = 2;
                $this->pi[$s] = $value;
                return;
            }
            if (\is_float($value)) { $this->pk[$s] = 3; $this->ps[$s] = __McPdoMyWire::putF64($value); return; }
        } elseif (\is_float($value) || \is_int($value)) {
            $this->pk[$s] = 4;
            $this->ps[$s] = (string) $value;
            return;
        }
        $this->pk[$s] = 4;
        if (\is_bool($value)) { $this->ps[$s] = $value ? '1' : ''; }
        // is_resource() last: it crashes on a denormal float (#331)
        elseif (\is_resource($value)) { $this->ps[$s] = (string) \stream_get_contents($value); }
        else { $this->ps[$s] = (string) $value; }
    }

    /** COM_STMT_EXECUTE tail: NULL bitmap, new-params-bound flag, a type per slot, then the non-NULL values. */
    private function encodeParams(): string
    {
        $n = \count($this->names);
        if ($n === 0) { return ''; }
        $bm = '';
        for ($i = 0; $i < $n; $i += 8) {
            $b = 0;
            for ($j = 0; $j < 8 && $i + $j < $n; $j++) {
                if ($this->pk[$i + $j] === 1) { $b |= 1 << $j; }
            }
            $bm .= \chr($b);
        }
        $ty = '';
        $va = '';
        for ($i = 0; $i < $n; $i++) {
            $k = $this->pk[$i];
            if ($k === 1) { $ty .= "\x06\0"; }
            elseif ($k === 2) { $ty .= "\x08\0"; $va .= __McPdoMyWire::putLe($this->pi[$i], 8); }
            elseif ($k === 5) { $ty .= "\x01\0"; $va .= \chr($this->pi[$i]); }
            elseif ($k === 3) { $ty .= "\x05\0"; $va .= $this->ps[$i]; }
            else { $ty .= "\xfd\0"; $va .= __McPdoMyWire::lenencInt(\strlen($this->ps[$i])) . $this->ps[$i]; }
        }
        return $bm . "\x01" . $ty . $va;
    }

    private function executeNative(): void
    {
        $n = \count($this->names);
        $ok = !$this->repeated;
        for ($i = 0; $i < $n && $ok; $i++) {
            if (!isset($this->pk[$i])) { $ok = false; }
        }
        $payload = $ok ? $this->encodeParams() : '';
        $this->pk = [];
        $this->pi = [];
        $this->ps = [];
        // php (measured): a count mismatch is its own bare HY093, raised before anything is sent
        if (!$ok) { throw self::badParam(); }
        if ($this->serverParams !== $n) {
            throw __McPdo::fail('HY000', 1210, true, 'Incorrect arguments to mysqld_stmt_execute', true);
        }
        $this->lastRows = $this->rowCount();
        $this->endCursor();
        $this->res = null;
        $this->idx = -1;
        $r = $this->db->wire->execute($this->stmtId, $payload, $this->db->unbuffered);
        $this->head = $r;
        if ($r->err !== null) { throw __McPdoMy::errFrom($r->err); }
        $r->entered = true;
        $this->res = $r;
    }

    public function paramCount(): int { return $this->nDistinct; }

    /** An unknown name is php core's HY093 carrying the text, not a silent false. */
    public function paramIndex(string $name): int
    {
        foreach ($this->names as $i => $n) {
            if ($n === $name) { return $i + 1; }
        }
        // `:1` is php's spelling of positional slot 1: answer 0 so the facade falls back to the number
        if (\ctype_digit(\substr($name, 1))) { return 0; }
        throw __McPdo::fail('HY093', 0, false, 'parameter was not defined', true);
    }

    /**
     * php's emulation (measured): NULL and PARAM_NULL are NULL; PARAM_INT is the
     * integer cast, bare; PARAM_BOOL is 1/0; a LOB or a stream is `_binary'..'`;
     * everything else is its string form quoted (true is '1', false is '').
     */
    public function bind(int $idx, mixed $value, int $type): void
    {
        if ($this->native) { $this->bindNative($idx, $value, $type); return; }
        if ($idx < 1 || $idx > \count($this->names)) {
            $this->over = true;
            return;
        }
        $t = $type & 7;
        if ($value === null || $t === PDO::PARAM_NULL) {
            $l = 'NULL';
        } elseif (\is_resource($value)) {
            $l = $this->db->quote((string) \stream_get_contents($value), PDO::PARAM_LOB);
        } elseif ($t === PDO::PARAM_LOB) {
            $l = $this->db->quote((string) $value, PDO::PARAM_LOB);
        } elseif ($t === PDO::PARAM_INT) {
            $l = (string) ((int) $value);
        } elseif ($t === PDO::PARAM_BOOL) {
            $l = (bool) $value ? '1' : '0';
        } else {
            $l = $this->db->quote(\is_bool($value) ? ($value ? '1' : '') : (string) $value, PDO::PARAM_STR);
        }
        $slot = $idx - 1;
        $this->lit[$slot] = $l;
        $this->set[$slot] = true;
        $nm = $this->names[$slot];
        if ($nm !== '?') {
            foreach ($this->names as $j => $n) {
                if ($n === $nm) { $this->lit[$j] = $l; $this->set[$j] = true; }
            }
        }
    }

    public function execute(): void
    {
        if ($this->native) { $this->executeNative(); return; }
        $n = \count($this->names);
        $ok = !$this->over;
        for ($i = 0; $i < $n && $ok; $i++) {
            if (!isset($this->set[$i])) { $ok = false; }
        }
        $lit = $this->lit;
        $this->lit = [];
        $this->set = [];
        $this->over = false;
        if (!$ok) {
            throw __McPdo::fail('HY093', 0, false, 'number of bound variables does not match number of tokens', true);
        }
        $q = $this->sql;
        if ($n > 0) {
            $q = '';
            $o = 0;
            for ($i = 0; $i < $n; $i++) {
                $p = $this->pos[$i];
                $q .= \substr($this->sql, $o, $p - $o) . $lit[$i];
                $o = $p + \strlen($this->names[$i]);
            }
            $q .= \substr($this->sql, $o);
        }
        $this->endCursor();
        $this->res = null;
        $this->idx = -1;
        $r = $this->db->wire->query($q, $this->db->unbuffered);
        $this->head = $r;
        if ($r->err !== null) { throw __McPdoMy::errFrom($r->err); }
        $r->entered = true;
        $this->res = $r;
    }

    /** The connection's open cursor is one of this statement's results. */
    private function ownsCursor(): bool
    {
        $c = $this->db->wire->openCursor;
        if ($c === null) { return false; }
        for ($n = $this->head; $n !== null; $n = $n->next) {
            if ($n === $c) { return true; }
        }
        return false;
    }

    /** Re-executing, closing or destroying the statement ends its own unbuffered result first (as pdo core's cursor_closer). */
    private function endCursor(): void
    {
        if ($this->ownsCursor()) { $this->db->wire->drainCursor(); }
    }

    public function step(): bool
    {
        if ($this->res === null) { return false; }
        if ($this->res->stream) {
            $r = $this->res;
            $row = $this->db->wire->cursorRow($r);
            if (\count($row) === 0) {
                $this->curRow = [];
                if ($r->err !== null) {
                    $e = $r->err;
                    $r->err = null;
                    throw __McPdoMy::errFrom($e);
                }
                return false;
            }
            $this->curRow = $row;
            return true;
        }
        if ($this->idx + 1 >= \count($this->res->rows)) { return false; }
        $this->idx++;
        return true;
    }

    /** The next result of a multi-statement query; a failed statement raises here, as php does. */
    public function nextRowset(): bool
    {
        if ($this->res === null) { return false; }
        if ($this->res->stream && !$this->res->done) {
            $r = $this->res;
            while (\count($this->db->wire->cursorRow($r, true)) > 0) {
            }
            if ($r->err !== null) {
                $e = $r->err;
                $r->err = null;
                $this->res = null;
                throw __McPdoMy::errFrom($e);
            }
        }
        if ($this->res->more) { $this->db->wire->cursorNext($this->res); }
        $n = $this->res->next;
        if ($n === null) { return false; }
        if ($n->err !== null) {
            $this->res = null;
            throw __McPdoMy::errFrom($n->err);
        }
        if ($this->native && !$n->isSet && $this->res->isSet) {
            // mysqlnd (measured): the closing OK of a CALL still reports the previous result set's columns
            $n->names = $this->res->names;
            $n->tables = $this->res->tables;
            $n->types = $this->res->types;
            $n->flags = $this->res->flags;
            $n->lens = $this->res->lens;
            $n->decs = $this->res->decs;
        }
        $n->entered = true;
        $this->res = $n;
        $this->idx = -1;
        return true;
    }

    public function columnCount(): int { return $this->res === null ? 0 : \count($this->res->names); }
    public function columnName(int $i): string { return $this->res === null ? '' : $this->res->names[$i]; }

    /**
     * php's key order: `native_type` (absent for a type php has no name for),
     * `pdo_type`, `flags`, `table` (the alias), `name`, `len`, `precision`.
     *
     * @return array<string,mixed>
     */
    public function columnMeta(int $i): array
    {
        if ($this->res === null) { return []; }
        $r = $this->res;
        $ty = $r->types[$i];
        $f = $r->flags[$i];
        $nt = self::nativeName($ty);
        /** @var array<string,mixed> */
        $m = [];
        if ($nt !== '') { $m['native_type'] = $nt; }
        $m['pdo_type'] = ($ty === 1 || $ty === 2 || $ty === 3 || $ty === 8 || $ty === 9 || $ty === 13 || $ty === 16)
            ? PDO::PARAM_INT : PDO::PARAM_STR;
        /** @var array<int,string> */
        $fl = [];
        if (($f & 1) !== 0) { $fl[] = 'not_null'; }
        if (($f & 2) !== 0) { $fl[] = 'primary_key'; }
        if (($f & 8) !== 0) { $fl[] = 'multiple_key'; }
        if (($f & 4) !== 0) { $fl[] = 'unique_key'; }
        if (($f & 16) !== 0) { $fl[] = 'blob'; }
        $m['flags'] = $fl;
        $m['table'] = $r->tables[$i];
        $m['name'] = $r->names[$i];
        $m['len'] = $r->lens[$i];
        $m['precision'] = $r->decs[$i];
        return $m;
    }

    private static function nativeName(int $t): string
    {
        if ($t === 253) { return 'VAR_STRING'; }
        if ($t === 254) { return 'STRING'; }
        if ($t === 3) { return 'LONG'; }
        if ($t === 8) { return 'LONGLONG'; }
        if ($t === 1) { return 'TINY'; }
        if ($t === 2) { return 'SHORT'; }
        if ($t === 9) { return 'INT24'; }
        if ($t === 4) { return 'FLOAT'; }
        if ($t === 5) { return 'DOUBLE'; }
        if ($t === 246) { return 'NEWDECIMAL'; }
        if ($t === 0) { return 'DECIMAL'; }
        if ($t === 252) { return 'BLOB'; }
        if ($t === 10) { return 'DATE'; }
        if ($t === 11) { return 'TIME'; }
        if ($t === 12) { return 'DATETIME'; }
        if ($t === 7) { return 'TIMESTAMP'; }
        if ($t === 13) { return 'YEAR'; }
        if ($t === 14) { return 'NEWDATE'; }
        if ($t === 16) { return 'BIT'; }
        if ($t === 245) { return 'JSON'; }
        if ($t === 247) { return 'ENUM'; }
        if ($t === 248) { return 'SET'; }
        if ($t === 249) { return 'TINY_BLOB'; }
        if ($t === 250) { return 'MEDIUM_BLOB'; }
        if ($t === 251) { return 'LONG_BLOB'; }
        if ($t === 255) { return 'GEOMETRY'; }
        if ($t === 6) { return 'NULL'; }
        return '';
    }

    /**
     * mysqlnd's text-protocol typing (measured): the integer types are int (an
     * UNSIGNED BIGINT above PHP_INT_MAX stays a string), FLOAT/DOUBLE float,
     * BIT the big-endian integer; DECIMAL, YEAR, dates, JSON, strings and blobs
     * are the text. A value that does not parse is HY000, not a closed
     * connection: the row was framed correctly, the stream is in sync.
     */
    public function columnValue(int $i): mixed
    {
        if ($this->res === null) { return null; }
        if ($this->res->stream) { $v = $this->curRow[$i]; } else { $v = $this->res->rows[$this->idx][$i]; }
        if ($v === null) { return null; }
        $t = $this->res->types[$i];
        if ($this->res->bin) { return $this->binValue($v, $t, $this->res->flags[$i], $this->res->decs[$i]); }
        if ($t === 253 || $t === 254 || $t === 252 || $t === 246) { return $v; }
        if ($t === 3 || $t === 8 || $t === 1 || $t === 2 || $t === 9) {
            $neg = $v !== '' && $v[0] === '-';
            $d = $neg ? \substr($v, 1) : $v;
            $n = \strlen($d);
            if ($n === 0 || $n > 20 || !\ctype_digit($d)) { throw self::badValue('integer'); }
            if ($n < 19) { return (int) $v; }
            if ($n === 19) {
                $max = $neg ? '9223372036854775808' : '9223372036854775807';
                if (\strcmp($d, $max) <= 0) { return (int) $v; }
                if ($neg || ($this->res->flags[$i] & 32) === 0) { throw self::badValue('integer'); }
                return $v;
            }
            if ($neg || ($this->res->flags[$i] & 32) === 0 || \strcmp($d, '18446744073709551615') > 0) { throw self::badValue('integer'); }
            return $v;
        }
        if ($t === 5 || $t === 4) {
            // is_numeric() also takes surrounding whitespace, which no server prints
            if (!\is_numeric($v) || \ctype_space($v[0]) || \ctype_space($v[\strlen($v) - 1])) { throw self::badValue('float'); }
            return (float) $v;
        }
        if ($t === 16) {
            $n = \strlen($v);
            if ($n < 1 || $n > 8) { throw self::badValue('bit'); }
            if ($n === 8 && \ord($v[0]) >= 128) { return self::dec($v); }
            $x = 0;
            for ($k = 0; $k < $n; $k++) { $x = ($x << 8) | \ord($v[$k]); }
            return $x;
        }
        return $v;
    }

    /**
     * mysqlnd's binary typing: the integer widths are int (an UNSIGNED BIGINT above PHP_INT_MAX stays a string), FLOAT and
     * DOUBLE float, BIT the big-endian integer; every other cell is already its final text (see parseBinRow).
     */
    private function binValue(string $v, int $t, int $flags, int $dec): mixed
    {
        $uns = ($flags & 32) !== 0;
        if ($t === 1) {
            $x = \ord($v);
            return !$uns && $x >= 128 ? $x - 256 : $x;
        }
        if ($t === 2) {
            $x = __McPdoMyWire::le16($v, 0);
            return !$uns && $x >= 32768 ? $x - 65536 : $x;
        }
        if ($t === 3 || $t === 9) {
            $x = __McPdoMyWire::le32($v, 0);
            return !$uns && $x >= 2147483648 ? $x - 4294967296 : $x;
        }
        if ($t === 8) {
            $hi = __McPdoMyWire::le32($v, 4);
            if ($uns && $hi >= 2147483648) { return self::dec(\strrev($v)); }
            return __McPdoMyWire::le32($v, 0) | ($hi << 32);
        }
        if ($t === 5) { return __McPdoMyWire::f64($v); }
        if ($t === 4) { return __McPdoMyWire::f32($v, $dec); }
        if ($t === 16) {
            $n = \strlen($v);
            if ($n < 1 || $n > 8) { throw self::badValue('bit'); }
            if ($n === 8 && \ord($v[0]) >= 128) { return self::dec($v); }
            $x = 0;
            for ($k = 0; $k < $n; $k++) { $x = ($x << 8) | \ord($v[$k]); }
            return $x;
        }
        return $v;
    }

    private static function dec(string $b): string { return __McPdoMyWire::beToDec($b); }

    private static function badValue(string $what): PDOException
    {
        return __McPdo::fail('HY000', 0, false, 'invalid ' . $what . ' value from server', true);
    }

    /** SELECT: the rows buffered; anything else: the rows it changed. */
    public function rowCount(): int
    {
        if ($this->res === null) { return $this->native ? $this->lastRows : 0; }
        // mysqlnd (measured): an unbuffered result reports 0 rows, before and after the last fetch
        return $this->res->isSet ? ($this->res->stream ? 0 : \count($this->res->rows)) : $this->res->affected;
    }

    public function closeCursor(): void
    {
        $this->endCursor();
        if ($this->res !== null) {
            $this->idx = \count($this->res->rows);
            $this->res->next = null;
        }
    }

    /** The statement object is going away: the server's copy is released exactly once. */
    public function close(): void
    {
        try {
            $this->endCursor();
        } catch (\Throwable $e) {
            // the wire is poisoned by whatever failed (gone, cancelled, malformed): the cursor went with the connection
        }
        $this->res = null;
        $this->head = null;
        if ($this->prepared) {
            $this->prepared = false;
            if ($this->stmtGen === $this->db->wire->gen) { $this->db->wire->closeStmt($this->stmtId); }
        }
    }

    public function errorInfo(): array { return __McPdo::triple('', 0, false, '', false); }
}

/**
 * mysqlnd turns TLS on when any of SSL_KEY / SSL_CERT / SSL_CA / SSL_CAPATH / SSL_CIPHER is set (SSL_VERIFY_SERVER_CERT alone does
 * not) and then verifies the server (chain AND host name) unless SSL_VERIFY_SERVER_CERT is false. Measured against php 8.5: with
 * verification off nothing about the CA is read; a missing SSL_CERT or an empty / unknown cipher fails; SSL_KEY without SSL_CERT is ignored.
 *
 * @param array<int,mixed> $options
 */
function __mc_pdo_mysql_tls_opts(array $options, string $host): ?__McPdoTlsOpts
{
    $want = false;
    foreach ([1006, 1007, 1008, 1009, 1010] as $k) {
        if (isset($options[$k])) { $want = true; }
    }
    if (!$want) { return null; }
    $o = new __McPdoTlsOpts();
    $o->host = $host;
    $verify = !isset($options[1013]) || (bool) $options[1013];
    $o->verifyChain = $verify;
    $o->verifyName = $verify;
    $ca = isset($options[1008]) ? $options[1008] : null;
    $capath = isset($options[1009]) ? $options[1009] : null;
    if ($verify) {
        if (\is_string($ca) && $ca !== '') { $o->caFile = $ca; }
        if (\is_string($capath) && $capath !== '') { $o->caDir = $capath; }
        if ($o->caFile === '' && $o->caDir === '') { $o->systemRoots = true; }
    }
    $cipher = isset($options[1010]) ? $options[1010] : null;
    // an empty list is invalid to OpenSSL: "\x01" is a name no cipher has
    if (\is_string($cipher)) { $o->ciphers = $cipher === '' ? "\x01" : $cipher; }
    $cert = isset($options[1007]) ? $options[1007] : null;
    $key = isset($options[1006]) ? $options[1006] : null;
    if (\is_string($cert) && $cert !== '') {
        $o->cert = $cert;
        if (\is_string($key)) { $o->key = $key; }
    }
    return $o;
}

/**
 * Entry point of the `mysql` arm of the facade. php (measured): the DSN carries
 * host, port, dbname, charset, unix_socket — a `user=` / `password=` key is NOT
 * read (unlike pdo_pgsql); the constructor arguments are the credentials.
 */
function __mc_pdo_mysql_open(string $rest, array $options, ?string $user, ?string $pw): __McPdoDrv
{
    $p = __McPdo::parseParams($rest);
    if (isset($p['unix_socket'])) {
        throw __McPdo::connectFail('HY000', 2002, 'unix_socket is not supported yet');
    }
    $host = isset($p['host']) && $p['host'] !== '' ? $p['host'] : 'localhost';
    $port = isset($p['port']) ? (int) $p['port'] : 3306;
    $db = isset($p['dbname']) ? $p['dbname'] : '';
    $charset = isset($p['charset']) ? $p['charset'] : 'utf8mb4';
    $tlsOpts = __mc_pdo_mysql_tls_opts($options, $host);
    $timeout = isset($options[PDO::ATTR_TIMEOUT]) ? (float) $options[PDO::ATTR_TIMEOUT] : 30.0;
    $multi = !isset($options[PDO::MYSQL_ATTR_MULTI_STATEMENTS]) || (bool) $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS];

    if (isset($options[PDO::MYSQL_ATTR_COMPRESS]) && (bool) $options[PDO::MYSQL_ATTR_COMPRESS]) {
        throw __McPdo::connectFail('HY000', 2000, 'compression is not supported');
    }
    // the driver cannot serve a LOAD DATA LOCAL INFILE request (CLIENT_LOCAL_FILES is never set): refuse up front
    $li = isset($options[PDO::MYSQL_ATTR_LOCAL_INFILE]) ? $options[PDO::MYSQL_ATTR_LOCAL_INFILE] : false;
    if ($li) { throw __McPdo::connectFail('HY000', 2000, 'LOCAL INFILE is not supported'); }

    $wire = new __McPdoMyWire($host, $port, $timeout);
    $wire->explicitTimeout = isset($options[PDO::ATTR_TIMEOUT]);
    try {
        $wire->handshake();
        $cs = __McPdoMyWire::charsetNo($charset, $wire->serverVersion);
        if ($cs === null) { throw __McPdo::connectFail('HY000', 2019, 'Unknown character set'); }
        $wire->auth($user === null ? '' : $user, $pw, $db, $cs,
            isset($options[PDO::MYSQL_ATTR_FOUND_ROWS]) && (bool) $options[PDO::MYSQL_ATTR_FOUND_ROWS], $multi,
            isset($options[PDO::MYSQL_ATTR_IGNORE_SPACE]) && (bool) $options[PDO::MYSQL_ATTR_IGNORE_SPACE], $tlsOpts);
    } catch (\Throwable $e) {
        $wire->close();
        throw $e;
    }
    $drv = new __McPdoMy($wire);
    $drv->baseCharset = $charset;
    $drv->baseAutocommit = !isset($options[PDO::ATTR_AUTOCOMMIT]) || (bool) $options[PDO::ATTR_AUTOCOMMIT];
    $drv->unbuffered = isset($options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY]) && !(bool) $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY];
    // ⚠ #318: reading an option with a (string) cast beside (float) $options[ATTR_TIMEOUT] crashes; is_string() does not
    $init = isset($options[PDO::MYSQL_ATTR_INIT_COMMAND]) ? $options[PDO::MYSQL_ATTR_INIT_COMMAND] : null;
    if (\is_string($init)) {
        $drv->initCmd = $init;
        try {
            $drv->run($init);
        } catch (PDOException $e) {
            $wire->close();
            throw __McPdo::connectFail($e->sqlstate, $e->driverCode, $e->driverMsg);
        }
    }
    return $drv;
}
