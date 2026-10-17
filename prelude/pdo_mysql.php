<?php

/**
 * pdo_mysql — the MySQL driver behind PDO, speaking the client/server protocol
 * over a plain socket (no libmysqlclient). Implements the {@see __McPdoDrv} seam.
 *
 * Text protocol with client-side (emulated) prepares, as pdo_mysql does by default.
 * The classic EOF packets are used throughout: CLIENT_DEPRECATE_EOF is not
 * requested, so there is one result framing to validate, not two.
 *
 * Parsing is ord()/substr() on purpose (no pack()/unpack()): the binary prelude
 * is gated on the PROGRAM calling it, and the row loop is the hot path.
 *
 * Deliberate gaps: TLS (`MYSQL_ATTR_SSL_*` is refused up front, never silently
 * downgraded), unix
 * sockets, native (binary protocol) prepares, compression.
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
    public int $affected = 0;
    public int $insertId = 0;
    public int $status = 0;
    public int $warnings = 0;
    public ?__McPdoMyErr $err = null;
    public ?__McPdoMyResult $next = null;
}

final class __McPdoMyWire
{
    // client capability flags
    private const CLIENT_LONG_PASSWORD = 0x1;
    private const CLIENT_FOUND_ROWS = 0x2;
    private const CLIENT_LONG_FLAG = 0x4;
    private const CLIENT_CONNECT_WITH_DB = 0x8;
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

    public bool $dead = false;
    public string $serverVersion = '';
    public int $connId = 0;
    /** The status flags of the latest OK/EOF packet (SERVER_STATUS_*). */
    public int $status = 0;
    /** The last OK packet's AUTO_INCREMENT id; 0 after any other command, as mysql_insert_id(). */
    public int $insertId = 0;
    /** The endpoint, for ATTR_CONNECTION_STATUS. */
    public string $host = '';
    /** libmysql's prefix is absent; php prints `SQLSTATE[HY000] [2002] ...` for every connect failure. */
    private bool $connecting = true;
    private bool $closed = false;
    /** One request/response cycle owns the wire; a second fiber on the same connection waits (see enter()). */
    private bool $busy = false;
    /** A command was sent and its last result packet has not arrived: the stream is mid-protocol. */
    private bool $inflight = false;
    private float $timeout = 30.0;
    /** ATTR_TIMEOUT was set by the program: only then does it also bound the idle wait for a response (see fill()). */
    public bool $explicitTimeout = false;
    /** A command was sent and not one byte of its response has arrived: the server is executing it. */
    private bool $awaiting = false;
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
    /** @var resource */
    private $s;
    private string $buf = '';
    private int $pos = 0;
    /** Offset after the last lstr(). */
    private int $lp = 0;

    public function __construct(string $host, int $port, float $timeout)
    {
        $this->host = $host;
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
        $this->setTimeout($timeout);
        $this->authDeadline = $timeout > 0.0 ? \__mc_monotonic_f() + 2.0 * $timeout : 0.0;
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
        // ⚠ php's ATTR_TIMEOUT is a CONNECT timeout. The idle wait for the START of a response (the server is running the
        // statement) is therefore unbounded unless the program set ATTR_TIMEOUT itself (superset); a packet that has begun
        // arriving, and connect/auth, are always bounded.
        $idle = $this->awaiting && !$this->connecting && !$this->explicitTimeout;
        $dl = $this->timeout > 0.0 && !$idle ? \__mc_monotonic_f() + 2.0 * $this->timeout + (float) $n / 65536.0 : 0.0;
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
            if ($len < 0xFFFFFF) { return $out; }
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
                    throw $this->ioFail(2006, 'MySQL server has gone away');
                }
                $m = $w >= \strlen($m) ? '' : \substr($m, $w);
            }
            // a payload that is an exact multiple of 0xFFFFFF ends with an empty frame
        } while ($o < $n || ($c === 0xFFFFFF && $o === $n));
    }

    /** Marks the connection dead and releases the fd (once). */
    private function closeSock(): void
    {
        // a command abandoned mid-flight (cancel, timeout) keeps running server-side: ask the server to stop it
        if (!$this->closed && $this->inflight && !$this->connecting && !$this->peerGone && $this->connId > 0) {
            $this->cancelRequest();
        }
        $this->dead = true;
        if ($this->closed) { return; }
        $this->closed = true;
        \fclose($this->s);
    }

    /** ponytail: Task 8.5 sends KILL QUERY <connId> from a second connection, under Async\shield; nothing to cancel with yet. */
    private function cancelRequest(): void
    {
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
        $this->closeSock();
        throw __McPdo::connectFail('HY000', 2059, "Authentication plugin '" . $plugin . "' cannot be loaded");
    }

    /**
     * HandshakeResponse41 and the rest of the authentication exchange, up to the OK packet.
     */
    public function auth(string $user, ?string $pw, string $db, int $charsetNo, bool $foundRows, bool $multi, bool $ignoreSpace): void
    {
        $pw = $pw === null ? '' : $pw;
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
        $caps &= $this->srvCaps | self::CLIENT_FOUND_ROWS | self::CLIENT_IGNORE_SPACE;
        $this->cliCaps = $caps;
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
            throw $this->malformed('unexpected authentication packet');
        }
    }

    /**
     * caching_sha2_password perform_full_authentication over a plain socket: ask for
     * the server's RSA key and send the password RSA-OAEP encrypted. Cleartext over
     * TLS arrives with the TLS phase.
     */
    private function fullAuth(string $pw, string $scramble): void
    {
        $this->sendPacket("\x02");
        $k = $this->packet(self::CTRL_CAP);
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

    /** One result (OK, ERR or a result set) of the response to a command. */
    private function readResult(): __McPdoMyResult
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
            $r->rows[] = $this->parseRow($p, $nc);
        }
    }

    /**
     * Runs one COM_QUERY and reads EVERY result it produces (a multi-statement
     * query is a chain). Never throws on a server error: the error lands in a
     * result's ->err and the stream is in sync. A protocol failure poisons the
     * connection and throws.
     */
    public function query(string $sql): __McPdoMyResult
    {
        $this->enter();
        try {
            return $this->queryRun($sql);
        } catch (\Throwable $t) {
            // a cancel/timeout/protocol failure inside a cycle leaves half a response on the wire: nobody may reuse it
            if ($this->inflight) { $this->closeSock(); }
            throw $t;
        } finally {
            $this->inflight = false;
            $this->busy = false;
        }
    }

    /**
     * Takes the wire for one cycle. Two fibers sharing a connection must not interleave packets.
     * ponytail: polling park (1 ms sleeper) bounded by ATTR_TIMEOUT, not a wait-queue; Async\Pool (Task 11) is the upgrade.
     * Without a scheduler a second entry is a re-entrancy bug, not contention.
     */
    private function enter(): void
    {
        if ($this->busy) {
            if (!\Runtime\AsyncHook::active()) { throw __McPdo::fail('HY000', 0, false, 'connection busy', true); }
            $sleeper = \Runtime\AsyncHook::sleeper();
            if ($sleeper === null) { throw __McPdo::fail('HY000', 0, false, 'connection busy', true); }
            // php: a timeout of 0 is unlimited
            $deadline = $this->timeout > 0.0 ? \__mc_monotonic_f() + $this->timeout : 0.0;
            while ($this->busy && !$this->dead) {
                if ($deadline > 0.0 && \__mc_monotonic_f() >= $deadline) {
                    throw __McPdo::fail('HY000', 0, false, 'timeout expired waiting for the connection', true);
                }
                $sleeper(0.001);
            }
        }
        $this->busy = true;
    }

    private function queryRun(string $sql): __McPdoMyResult
    {
        if ($this->dead) { throw $this->ioFail(2006, 'MySQL server has gone away'); }
        $this->inflight = true;
        $this->insertId = 0;
        $this->seq = 0;
        $this->sendPacket("\x03" . $sql);
        $this->awaiting = true;
        $this->respStart = true;
        $first = null;
        $last = null;
        $n = 0;
        while (true) {
            $r = $this->readResult();
            if ($first === null) { $first = $r; } else { $last->next = $r; }
            $last = $r;
            if (!$r->isSet && $r->err === null) { $this->insertId = $r->insertId; }
            if ($r->err !== null || ($r->status & 8) === 0) { break; }
            // ponytail: bounds a server that keeps promising "more results"; no real batch comes near it
            if (++$n > 100000) { throw $this->malformed('too many result sets'); }
        }
        $this->inflight = false;
        return $first;
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
            $this->inflight = false;
            $this->busy = false;
        }
    }

    public function close(): void
    {
        if (!$this->dead && !$this->busy && !$this->connecting) {
            try { $this->seq = 0; $this->sendPacket("\x01"); } catch (PDOException $e) {}
        }
        $this->closeSock();
    }
}

final class __McPdoMy implements __McPdoDrv
{
    public __McPdoMyWire $wire;
    /** ATTR_EMULATE_PREPARES: pdo_mysql's default is on; native prepares arrive with the binary protocol. */
    public bool $emulate = true;
    /** ATTR_AUTOCOMMIT as the program last set it (the server starts in autocommit mode). */
    public bool $autocommit = true;
    /** ATTR_FETCH_TABLE_NAMES: stored and read back; column names are not prefixed yet. */
    public bool $fetchTables = false;
    /** ATTR_DEFAULT_STR_PARAM = PARAM_STR_NATL. */
    public bool $natl = false;

    public function __construct(__McPdoMyWire $wire)
    {
        $this->wire = $wire;
    }

    public function name(): string { return 'mysql'; }

    public function prepare(string $sql): __McPdoDrvStmt
    {
        if (!$this->emulate) {
            throw __McPdo::fail('HY000', 0, false, 'native prepared statements are not supported yet (ATTR_EMULATE_PREPARES=false)', true);
        }
        return new __McPdoMyStmt($this, $sql);
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
        // results are always buffered; unbuffered cursors arrive with the async phase
        if ($attr === PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) {
            if (!(bool) $value) { throw __McPdo::fail('HY000', 0, false, 'unbuffered queries are not supported yet', true); }
            return true;
        }
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
        if ($attr === PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) { return true; }
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
 * One PDOStatement over emulated prepares: execute() splices the quoted values
 * into the SQL at the byte offsets the scanner recorded and sends one COM_QUERY
 * with the whole result buffered. A repeated `:name` is one slot per occurrence,
 * bound by its distinct name.
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

    public function __construct(__McPdoMy $db, string $sql)
    {
        $this->db = $db;
        $sc = __McPdoSql::scan($sql, 'mysql');
        $this->sql = $sc->sql;
        $this->names = $sc->names;
        $this->pos = $sc->pos;
        /** @var array<string,bool> */
        $seen = [];
        foreach ($this->names as $n) {
            if ($n === '?') { $this->nDistinct++; }
            elseif (!isset($seen[$n])) { $seen[$n] = true; $this->nDistinct++; }
        }
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
        $this->res = null;
        $this->idx = -1;
        $r = $this->db->wire->query($q);
        if ($r->err !== null) { throw __McPdoMy::errFrom($r->err); }
        $this->res = $r;
    }

    public function step(): bool
    {
        if ($this->res === null) { return false; }
        if ($this->idx + 1 >= \count($this->res->rows)) { return false; }
        $this->idx++;
        return true;
    }

    /** The next result of a multi-statement query; a failed statement raises here, as php does. */
    public function nextRowset(): bool
    {
        if ($this->res === null) { return false; }
        $n = $this->res->next;
        if ($n === null) { return false; }
        if ($n->err !== null) {
            $this->res = null;
            throw __McPdoMy::errFrom($n->err);
        }
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
        $v = $this->res->rows[$this->idx][$i];
        if ($v === null) { return null; }
        $t = $this->res->types[$i];
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

    private static function dec(string $b): string { return __McPdoMyWire::beToDec($b); }

    private static function badValue(string $what): PDOException
    {
        return __McPdo::fail('HY000', 0, false, 'invalid ' . $what . ' value from server', true);
    }

    /** SELECT: the rows buffered; anything else: the rows it changed. */
    public function rowCount(): int
    {
        if ($this->res === null) { return 0; }
        return $this->res->isSet ? \count($this->res->rows) : $this->res->affected;
    }

    public function closeCursor(): void
    {
        if ($this->res !== null) {
            $this->idx = \count($this->res->rows);
            $this->res->next = null;
        }
    }

    public function close(): void { $this->res = null; }

    public function errorInfo(): array { return __McPdo::triple('', 0, false, '', false); }
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
    // TLS arrives in its own phase: refused up front, never silently downgraded
    foreach ([1006, 1007, 1008, 1009, 1010, 1011, 1013] as $k) {
        if (isset($options[$k])) { throw __McPdo::connectFail('HY000', 2026, 'TLS is not supported yet (MYSQL_ATTR_SSL_* options)'); }
    }
    $timeout = isset($options[PDO::ATTR_TIMEOUT]) ? (float) $options[PDO::ATTR_TIMEOUT] : 30.0;
    $multi = !isset($options[PDO::MYSQL_ATTR_MULTI_STATEMENTS]) || (bool) $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS];

    if (isset($options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY]) && !(bool) $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY]) {
        throw __McPdo::connectFail('HY000', 2000, 'unbuffered queries are not supported yet');
    }
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
            isset($options[PDO::MYSQL_ATTR_IGNORE_SPACE]) && (bool) $options[PDO::MYSQL_ATTR_IGNORE_SPACE]);
    } catch (\Throwable $e) {
        $wire->close();
        throw $e;
    }
    $drv = new __McPdoMy($wire);
    // ⚠ #318: reading an option with a (string) cast beside (float) $options[ATTR_TIMEOUT] crashes; is_string() does not
    $init = isset($options[PDO::MYSQL_ATTR_INIT_COMMAND]) ? $options[PDO::MYSQL_ATTR_INIT_COMMAND] : null;
    if (\is_string($init)) {
        try {
            $drv->run($init);
        } catch (PDOException $e) {
            $wire->close();
            throw __McPdo::connectFail($e->sqlstate, $e->driverCode, $e->driverMsg);
        }
    }
    return $drv;
}
