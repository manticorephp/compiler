<?php

/**
 * pdo_pgsql — the PostgreSQL driver behind PDO, speaking the wire protocol
 * over a plain socket (no libpq). Implements the {@see __McPdoDrv} seam.
 *
 * Parsing is ord()/substr() on purpose: unpack() allocates an array per field
 * and the DataRow loop is the hot path.
 *
 * Deliberate gap: TLS. `sslmode=prefer` (php's default) connects in plaintext
 * and `sslmode=require|verify-*` is refused up front rather than silently
 * downgraded.
 */

/** One ErrorResponse, already rendered the way libpq's PQerrorMessage() does. */
final class __McPdoPgErr
{
    public string $sev = 'ERROR';
    public string $code = '08006';
    public string $msg = '';
    public string $detail = '';
    public string $hint = '';
    public string $internal = '';
    public string $context = '';
    public int $pos = 0;
    /** `ERROR:  msg\nLINE 1: ...\n   ^\nDETAIL:  ...` without the trailing newline. */
    public string $text = '';
}

/**
 * The outcome of one simple query. A class and not a keyed array: it mixes
 * rows, strings and an object, and a mixed-value array loses its element type.
 * With several statements in the query, the LAST statement's result is kept.
 */
final class __McPdoPgResult
{
    /** @var array<int,string> */
    public array $cols = [];
    /** @var array<int,int> */
    public array $oids = [];
    /** @var array<int,int> source table OID per column, 0 when it is not a plain column */
    public array $tbls = [];
    /** @var array<int,int> pg_type.typlen per column */
    public array $lens = [];
    /** @var array<int,int> atttypmod per column */
    public array $mods = [];
    /** @var array<int,array<int,?string>> */
    public array $rows = [];
    public string $tag = '';
    public int $affected = 0;
    public ?__McPdoPgErr $err = null;
    /** The extended protocol's ParseComplete arrived (the named statement exists server-side). */
    public bool $parsed = false;
}

final class __McPdoPgWire
{
    public bool $dead = false;
    /** ReadyForQuery status: I idle, T in a transaction, E failed transaction. */
    public string $status = 'I';
    public string $serverVersion = '';
    /** BackendKeyData's process id; 0 once the connection is gone, as libpq's PQbackendPID(). */
    public int $pid = 0;
    /** ParameterStatus values ATTR_SERVER_INFO reports. */
    public string $clientEnc = '';
    public string $isSuper = '';
    public string $sessAuth = '';
    public string $dateStyle = '';
    /** standard_conforming_strings: with it off a backslash in a quoted literal is an escape. */
    public bool $stdStrings = true;
    /** The server's own error of the request in flight: libpq shows it ahead of "server closed the connection". */
    private string $pendErr = '';
    /** libpq's prefix for every failure while connecting. */
    public string $where = '';
    private bool $connecting = true;
    private bool $closed = false;
    /** One request/response cycle owns the wire; a second fiber on the same connection waits (see enter()). */
    private bool $busy = false;
    /** A request was sent (or owed answers are being read) and its ReadyForQuery has not arrived: the stream is mid-protocol. */
    private bool $inflight = false;
    private float $timeout = 30.0;
    private string $addr = '';
    /** BackendKeyData's secret: with the pid it authorises a CancelRequest. */
    private string $secret = '';
    /** @var array<int,string> statement names whose Close arrived while the wire was busy */
    private array $deferred = [];
    /** SASL progress: 0 none, 1 initial response sent, 2 client-final sent, 3 server signature verified. */
    private int $sasl = 0;
    private bool $authOk = false;
    /** Close+Sync round trips sent whose ReadyForQuery has not been read yet. */
    private int $owed = 0;
    /** @var resource */
    private $s;
    private string $buf = '';
    private int $pos = 0;
    private string $nonce = '';
    private string $serverSig = '';

    public function __construct(string $host, int $port, float $timeout)
    {
        $ip = $host;
        if (\strpos($host, ':') === false && \ip2long($host) === false) {
            $ip = \gethostbyname($host);
        }
        $shown = $ip === $host ? '"' . $host . '"' : '"' . $host . '" (' . $ip . ')';
        $this->where = 'connection to server at ' . $shown . ', port ' . (string) $port . ' failed: ';
        if ($ip === $host && \strpos($host, ':') === false && \ip2long($host) === false) {
            $gai = \PHP_OS_FAMILY === 'Darwin'
                ? 'nodename nor servname provided, or not known' : 'Name or service not known';
            throw __McPdo::connectFail('08006', 7,
                'could not translate host name "' . $host . '" to address: ' . $gai);
        }
        $en = 0;
        $es = '';
        $s = \stream_socket_client('tcp://' . (\strpos($host, ':') === false ? $host : '[' . $host . ']')
            . ':' . (string) $port, $en, $es, $timeout);
        if ($s === false) {
            throw __McPdo::connectFail('08006', 7, $this->where . $es
                . "\n\tIs the server running on that host and accepting TCP/IP connections?");
        }
        $this->s = $s;
        $this->addr = 'tcp://' . (\strpos($host, ':') === false ? $host : '[' . $host . ']') . ':' . (string) $port;
        $this->setTimeout($timeout);
    }

    public function setTimeout(float $t): void
    {
        $this->timeout = $t;
        $sec = (int) $t;
        \stream_set_timeout($this->s, $sec, (int) (($t - $sec) * 1000000));
    }

    /** @param array<string,string> $params extra startup parameters */
    public function startup(string $user, string $db, array $params): void
    {
        if (\strpos($user, "\0") !== false || \strpos($db, "\0") !== false) {
            throw $this->startFail('invalid NUL byte in user or database name');
        }
        $p = "user\0" . $user . "\0database\0" . $db . "\0";
        foreach ($params as $k => $v) {
            if (\strpos($k, "\0") !== false || \strpos($v, "\0") !== false) {
                throw $this->startFail('invalid NUL byte in connection parameter');
            }
            $p .= $k . "\0" . $v . "\0";
        }
        $this->send('', self::be32(196608) . $p . "\0");
    }

    public function send(string $type, string $body): void
    {
        $this->sendRaw($type . self::be32(\strlen($body) + 4) . $body);
    }

    private function sendRaw(string $m): void
    {
        if ($this->dead) { throw $this->ioFail('no connection to the server'); }
        while ($m !== '') {
            $w = \fwrite($this->s, $m);
            if ($w === false || $w === 0) {
                throw $this->ioFail("could not send data to server");
            }
            $m = $w >= \strlen($m) ? '' : \substr($m, $w);
        }
    }

    /** Top the buffer up to $n unread bytes. Compacts once, not per fread. */
    private function fill(int $n): void
    {
        if ($this->pos > 0) {
            $this->buf = \substr($this->buf, $this->pos);
            $this->pos = 0;
        }
        while (\strlen($this->buf) < $n) {
            $c = \fread($this->s, 65536);
            if ($c === false || $c === '') {
                throw $this->ioFail(\feof($this->s)
                    ? "server closed the connection unexpectedly\n\tThis probably means the server terminated abnormally\n\tbefore or while processing the request."
                    : 'timeout expired');
            }
            $this->buf .= $c;
        }
    }

    /** @return array{0:string,1:string} message type byte and body */
    public function msg(): array
    {
        if (\strlen($this->buf) - $this->pos < 5) { $this->fill(5); }
        $p = $this->pos;
        $t = $this->buf[$p];
        $len = (\ord($this->buf[$p + 1]) << 24) | (\ord($this->buf[$p + 2]) << 16)
            | (\ord($this->buf[$p + 3]) << 8) | \ord($this->buf[$p + 4]);
        if ($len < 4 || $len > 1073741824) { throw $this->ioFail('invalid message length'); }
        if (\strlen($this->buf) - $p < 1 + $len) {
            $this->fill(1 + $len);
            $p = 0;
        }
        $body = \substr($this->buf, $p + 5, $len - 4);
        $this->pos = $p + 1 + $len;
        return [$t, $body];
    }

    /** Marks the connection dead and releases the fd (once). */
    private function closeSock(): void
    {
        // a request abandoned mid-flight (cancel, timeout) would keep running server-side: ask the server to stop it
        if (!$this->closed && $this->inflight && !$this->connecting && $this->pid > 0 && $this->secret !== '') {
            $this->cancelRequest();
        }
        $this->dead = true;
        // PQtransactionStatus() of a lost connection: PQTRANS_UNKNOWN, which php reads as "in a transaction"
        $this->status = 'U';
        $this->pid = 0;
        if ($this->closed) { return; }
        $this->closed = true;
        \fclose($this->s);
    }

    /** Best effort, on a throwaway connection as libpq's PQcancel: 16 bytes, no reply; any failure is ignored. */
    private function cancelRequest(): void
    {
        // a cancelled task re-raises at every park, and the connect parks: hold the cancellation back for this flush.
        // Named by string, not spelled, so a synchronous PDO program does not pull the scheduler in (see \Runtime\AsyncHook).
        if (\Runtime\AsyncHook::active() && \function_exists('Async\\shield')) {
            $shield = 'Async\\shield';
            $shield(function (): void { $this->sendCancel(); });
            return;
        }
        $this->sendCancel();
    }

    private function sendCancel(): void
    {
        try {
            $en = 0;
            $es = '';
            $c = \stream_socket_client($this->addr, $en, $es, 2.0);
            if ($c !== false) {
                \fwrite($c, self::be32(8 + \strlen($this->secret) + 4) . self::be32(80877102) . self::be32($this->pid) . $this->secret);
                \fclose($c);
            }
        } catch (\Throwable $t) {
        }
    }

    private function ioFail(string $m): PDOException
    {
        $this->closeSock();
        if ($this->connecting) { return __McPdo::connectFail('08006', 7, $this->where . $m); }
        // libpq hands php a result with NO SQLSTATE for a lost connection, so php reports HY000 (measured)
        if ($this->pendErr !== '') { $m = $this->pendErr . "\n" . $m; $this->pendErr = ''; }
        return __McPdo::fail('HY000', 7, true, $m, true);
    }

    /** pack('N') without pack(): the binary prelude is gated on the PROGRAM calling it. */
    private static function be32(int $n): string
    {
        return \chr(($n >> 24) & 255) . \chr(($n >> 16) & 255) . \chr(($n >> 8) & 255) . \chr($n & 255);
    }

    private static function be16(int $n): string
    {
        return \chr(($n >> 8) & 255) . \chr($n & 255);
    }

    private static function frame(string $t, string $body): string
    {
        return $t . self::be32(\strlen($body) + 4) . $body;
    }

    private static function i32(string $b, int $o): int
    {
        return (\ord($b[$o]) << 24) | (\ord($b[$o + 1]) << 16) | (\ord($b[$o + 2]) << 8) | \ord($b[$o + 3]);
    }

    /**
     * SCRAM-SHA-256 client-final. Pure, so the RFC 7677 vector can test it.
     * libpq sends an empty user name (`n=`); PostgreSQL takes it from the
     * startup packet. SASLprep is not applied — ASCII passwords only.
     *
     * @return array{0:string,1:string} [client-final-message, expected server signature (base64)]
     */
    public static function scram(string $pw, string $clientNonce, string $serverFirst, string $user = ''): array
    {
        $r = '';
        $s = '';
        $is = '';
        foreach (\explode(',', $serverFirst) as $p) {
            if (\strlen($p) < 2 || $p[1] !== '=') { continue; }
            if ($p[0] === 'r') { $r = \substr($p, 2); }
            elseif ($p[0] === 's') { $s = \substr($p, 2); }
            elseif ($p[0] === 'i') { $is = \substr($p, 2); }
        }
        if ($r === '') { throw __McPdo::connectFail('08006', 7, 'SCRAM server-first-message has no nonce (r=)'); }
        if ($s === '') { throw __McPdo::connectFail('08006', 7, 'SCRAM server-first-message has no salt (s=)'); }
        if ($is === '') { throw __McPdo::connectFail('08006', 7, 'SCRAM server-first-message has no iteration count (i=)'); }
        if (\strlen($r) <= \strlen($clientNonce) || \substr($r, 0, \strlen($clientNonce)) !== $clientNonce) {
            throw __McPdo::connectFail('08006', 7, 'SCRAM server nonce does not extend the client nonce');
        }
        $iter = 0;
        $ni = \strlen($is);
        for ($k = 0; $k < $ni; $k++) {
            $d = \ord($is[$k]) - 48;
            if ($d < 0 || $d > 9 || $k >= 9) { throw __McPdo::connectFail('08006', 7, 'invalid SCRAM iteration count'); }
            $iter = $iter * 10 + $d;
        }
        // ⚠ upper bound: a hostile server must not be able to pin the CPU with i=2^31
        if ($iter < 1 || $iter > 16777216) { throw __McPdo::connectFail('08006', 7, 'invalid SCRAM iteration count'); }
        $salt = \base64_decode($s, true);
        if ($salt === false || $salt === '') { throw __McPdo::connectFail('08006', 7, 'invalid SCRAM salt'); }
        $salted = \hash_pbkdf2('sha256', $pw, $salt, $iter, 32, true);
        $ck = \hash_hmac('sha256', 'Client Key', $salted, true);
        $withoutProof = 'c=biws,r=' . $r;
        $authMsg = 'n=' . $user . ',r=' . $clientNonce . ',' . $serverFirst . ',' . $withoutProof;
        $proof = $ck ^ \hash_hmac('sha256', $authMsg, \hash('sha256', $ck, true), true);
        $serverKey = \hash_hmac('sha256', 'Server Key', $salted, true);
        return [$withoutProof . ',p=' . \base64_encode($proof),
                \base64_encode(\hash_hmac('sha256', $authMsg, $serverKey, true))];
    }

    private function startFail(string $text): PDOException
    {
        $this->closeSock();
        return __McPdo::connectFail('08006', 7, $this->where . $text);
    }

    /** Runs the authentication exchange up to the first ReadyForQuery. */
    public function auth(string $user, ?string $pw): void
    {
        while (true) {
            [$t, $b] = $this->msg();
            if ($t === 'E') { throw $this->startFail($this->parseErr($b, '')->text); }
            if ($t === 'S') { $this->paramStatus($b); continue; }
            if ($t === 'K') {
                if (\strlen($b) >= 4) { $this->pid = self::i32($b, 0); $this->secret = \substr($b, 4); }
                continue;
            }
            if ($t === 'Z') {
                if (!$this->authOk || \strlen($b) < 1) { throw $this->startFail('server did not complete authentication'); }
                $this->status = $b[0];
                $this->connecting = false;
                return;
            }
            if ($t !== 'R') { continue; }
            if (\strlen($b) < 4) { throw $this->startFail('protocol error: truncated message'); }
            $code = self::i32($b, 0);
            if ($code === 0) {
                // ⚠ AuthenticationOk straight after a SASL exchange the server never proved is a downgrade
                if ($this->sasl !== 0 && $this->sasl !== 3) {
                    throw $this->startFail('SCRAM exchange incomplete: server did not prove its identity');
                }
                $this->authOk = true;
                continue;
            }
            if ($this->sasl !== 0 && $code !== 11 && $code !== 12) {
                throw $this->startFail('unexpected authentication request during SCRAM exchange');
            }
            if ($code === 3 || $code === 5 || $code === 10) {
                if ($pw === null) { throw $this->startFail('fe_sendauth: no password supplied'); }
            }
            if (($code === 3 || $code === 5) && \strpos($pw, "\0") !== false) {
                throw $this->startFail('password contains a NUL byte');
            }
            if ($code === 3) {
                $this->send('p', $pw . "\0");
            } elseif ($code === 5) {
                $this->send('p', 'md5' . \md5(\md5($pw . $user) . \substr($b, 4, 4)) . "\0");
            } elseif ($code === 10) {
                if (\strpos($b, "SCRAM-SHA-256\0") === false) {
                    throw $this->startFail("none of the server's SASL authentication mechanisms are supported");
                }
                $this->sasl = 1;
                $this->nonce = \base64_encode(\random_bytes(18));
                $init = 'n,,n=,r=' . $this->nonce;
                $this->send('p', "SCRAM-SHA-256\0" . self::be32(\strlen($init)) . $init);
            } elseif ($code === 11) {
                if ($this->sasl !== 1) { throw $this->startFail('unexpected SCRAM message'); }
                try {
                    [$final, $this->serverSig] = self::scram($pw === null ? '' : $pw, $this->nonce, \substr($b, 4), '');
                } catch (PDOException $e) {
                    $this->closeSock();
                    throw $e;
                }
                $this->sasl = 2;
                $this->send('p', $final);
            } elseif ($code === 12) {
                if ($this->sasl !== 2) { throw $this->startFail('unexpected SCRAM message'); }
                $v = \substr($b, 4);
                if (\substr($v, 0, 2) !== 'v=' || !\hash_equals($this->serverSig, \substr($v, 2))) {
                    throw $this->startFail('SCRAM-SHA-256 server signature mismatch');
                }
                $this->sasl = 3;
            } else {
                throw $this->startFail('authentication method ' . (string) $code . ' not supported');
            }
        }
    }

    private function paramStatus(string $b): void
    {
        $z = \strpos($b, "\0");
        if ($z === false) { return; }
        $e = \strpos($b, "\0", $z + 1);
        if ($e === false) { return; }
        $k = \substr($b, 0, $z);
        $v = \substr($b, $z + 1, $e - $z - 1);
        if ($k === 'server_version') { $this->serverVersion = $v; }
        elseif ($k === 'client_encoding') { $this->clientEnc = $v; }
        elseif ($k === 'is_superuser') { $this->isSuper = $v; }
        elseif ($k === 'session_authorization') { $this->sessAuth = $v; }
        elseif ($k === 'DateStyle') { $this->dateStyle = $v; }
        elseif ($k === 'standard_conforming_strings') { $this->stdStrings = $v !== 'off'; }
    }

    private function truncated(): PDOException
    {
        return $this->ioFail('protocol error: truncated message');
    }

    /** A RowDescription body into $res (columns, type OIDs, source tables, sizes, modifiers). */
    private function rowDesc(string $b, __McPdoPgResult $res): void
    {
        $res->cols = [];
        $res->oids = [];
        $res->tbls = [];
        $res->lens = [];
        $res->mods = [];
        $res->rows = [];
        $bl = \strlen($b);
        if ($bl < 2) { throw $this->truncated(); }
        $n = (\ord($b[0]) << 8) | \ord($b[1]);
        $o = 2;
        for ($i = 0; $i < $n; $i++) {
            $z = \strpos($b, "\0", $o);
            if ($z === false || $z + 19 > $bl) { throw $this->truncated(); }
            $res->cols[] = \substr($b, $o, $z - $o);
            $res->tbls[] = self::i32($b, $z + 1);
            $res->oids[] = self::i32($b, $z + 7);
            $l = (\ord($b[$z + 11]) << 8) | \ord($b[$z + 12]);
            $res->lens[] = $l >= 32768 ? $l - 65536 : $l;
            $m = self::i32($b, $z + 13);
            $res->mods[] = $m >= 2147483648 ? $m - 4294967296 : $m;
            $o = $z + 19;
        }
        if ($o !== $bl) { throw $this->truncated(); }
    }

    /**
     * A DataRow body. Every length is checked against the body: a hostile
     * server must not be able to make substr() read (or us allocate) past it.
     *
     * @return array<int,?string>
     */
    private function dataRow(string $b, int $ncols): array
    {
        $bl = \strlen($b);
        if ($bl < 2) { throw $this->truncated(); }
        $n = (\ord($b[0]) << 8) | \ord($b[1]);
        if ($n !== $ncols) { throw $this->truncated(); }
        $o = 2;
        /** @var array<int,?string> */
        $row = [];
        for ($i = 0; $i < $n; $i++) {
            if ($o + 4 > $bl) { throw $this->truncated(); }
            $l = (\ord($b[$o]) << 24) | (\ord($b[$o + 1]) << 16) | (\ord($b[$o + 2]) << 8) | \ord($b[$o + 3]);
            $o += 4;
            if ($l === 0xFFFFFFFF) { $row[] = null; continue; }
            if ($o + $l > $bl) { throw $this->truncated(); }
            $row[] = \substr($b, $o, $l);
            $o += $l;
        }
        if ($o !== $bl) { throw $this->truncated(); }
        return $row;
    }

    /** A CommandComplete body: `INSERT 0 3`, `UPDATE 2`, `SELECT 5`, `DELETE 0`, `MERGE 1`, `COPY 4`, `CREATE TABLE`. */
    private function cmdTag(string $b, __McPdoPgResult $res): void
    {
        $bl = \strlen($b);
        if ($bl < 1 || $b[$bl - 1] !== "\0") { throw $this->truncated(); }
        $res->tag = \substr($b, 0, $bl - 1);
        $sp = \strrpos($res->tag, ' ');
        $res->affected = $sp === false ? 0 : (int) \substr($res->tag, $sp + 1);
    }

    /** Runs one query string over the simple protocol. Never throws on a server error. */
    public function simple(string $sql): __McPdoPgResult
    {
        $this->enter();
        try {
            return $this->simpleRun($sql);
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
            $deadline = \__mc_monotonic_f() + $this->timeout;
            while ($this->busy && !$this->dead) {
                if (\__mc_monotonic_f() >= $deadline) {
                    throw __McPdo::fail('HY000', 0, false, 'timeout expired waiting for the connection', true);
                }
                $sleeper(0.001);
            }
        }
        $this->busy = true;
    }

    private function simpleRun(string $sql): __McPdoPgResult
    {
        $this->settle();
        $this->send('Q', $sql . "\0");
        $res = new __McPdoPgResult();
        $seenT = false;
        $copy = 0;
        while (true) {
            [$t, $b] = $this->msg();
            if ($t === 'D') {
                $res->rows[] = $this->dataRow($b, \count($res->cols));
            } elseif ($t === 'T') {
                $this->rowDesc($b, $res);
                $seenT = true;
            } elseif ($t === 'C') {
                if (!$seenT) {
                    $res->cols = [];
                    $res->oids = [];
                    $res->rows = [];
                }
                $seenT = false;
                $this->cmdTag($b, $res);
            } elseif ($t === 'E') {
                if ($res->err === null) { $res->err = $this->parseErr($b, $sql); $this->pendErr = $res->err->text; }
            } elseif ($t === 'S') {
                $this->paramStatus($b);
            } elseif ($t === 'G') {
                // COPY FROM STDIN: refuse it, the server answers with an error and ReadyForQuery
                $copy = 4;
                $this->send('f', "COPY is not supported\0");
            } elseif ($t === 'H') {
                $copy = 3;
            } elseif ($t === 'Z') {
                if (\strlen($b) < 1) { throw $this->truncated(); }
                $this->status = $b[0];
                $this->inflight = false;
                // php (measured): HY000, code = PGRES_COPY_IN 4 / PGRES_COPY_OUT 3, empty message; real COPY is later work
                if ($copy > 0) { throw __McPdo::fail('HY000', $copy, true, '', true); }
                return $res;
            }
        }
    }

    /**
     * One extended-protocol round trip: [Parse + Describe(S)] Bind Execute Sync,
     * all in one write. Text results; a parameter is text unless $bin[i] says
     * binary. Never throws on a server error — the error lands in $res->err
     * after the stream is drained to ReadyForQuery, so the connection stays usable.
     * A ParameterDescription is validated and otherwise ignored: the server
     * itself rejects a Bind whose count disagrees (php relies on the same).
     * When !$parse, $res already holds the statement's row description.
     *
     * @param array<int,?string> $params
     * @param array<int,bool> $bin
     */
    public function extended(string $name, string $sql, bool $parse, array $params, array $bin, __McPdoPgResult $res): void
    {
        $np = \count($params);
        if ($np > 65535) { throw __McPdo::fail('HY000', 0, false, 'too many parameters', true); }
        $this->enter();
        try {
            $this->extendedRun($name, $sql, $parse, $params, $bin, $res, $np);
        } catch (\Throwable $t) {
            if ($this->inflight) { $this->closeSock(); }
            throw $t;
        } finally {
            $this->inflight = false;
            $this->busy = false;
        }
    }

    /**
     * @param array<int,?string> $params
     * @param array<int,bool> $bin
     */
    private function extendedRun(string $name, string $sql, bool $parse, array $params, array $bin, __McPdoPgResult $res, int $np): void
    {
        $this->settle();
        $m = '';
        if ($parse) {
            $m .= self::frame('P', $name . "\0" . $sql . "\0\0\0");
            $m .= self::frame('D', 'S' . $name . "\0");
        }
        $anyBin = false;
        foreach ($bin as $f) { if ($f) { $anyBin = true; } }
        $b = "\0" . $name . "\0";
        if ($anyBin) {
            $b .= self::be16($np);
            for ($i = 0; $i < $np; $i++) { $b .= self::be16(($bin[$i] ?? false) ? 1 : 0); }
        } else {
            $b .= "\0\0";
        }
        $b .= self::be16($np);
        for ($i = 0; $i < $np; $i++) {
            $v = $params[$i] ?? null;
            $b .= $v === null ? "\xff\xff\xff\xff" : self::be32(\strlen($v)) . $v;
        }
        $m .= self::frame('B', $b . "\0\0");
        $m .= self::frame('E', "\0\0\0\0\0");
        $m .= self::frame('S', '');
        $this->sendRaw($m);

        $copy = 0;
        while (true) {
            [$t, $b] = $this->msg();
            if ($t === 'D') {
                $res->rows[] = $this->dataRow($b, \count($res->cols));
            } elseif ($t === '1') {
                if ($b !== '') { throw $this->truncated(); }
                $res->parsed = true;
            } elseif ($t === '2') {
                if ($b !== '') { throw $this->truncated(); }
            } elseif ($t === 't') {
                $bl = \strlen($b);
                if ($bl < 2 || $bl !== 2 + 4 * ((\ord($b[0]) << 8) | \ord($b[1]))) { throw $this->truncated(); }
            } elseif ($t === 'T') {
                $this->rowDesc($b, $res);
            } elseif ($t === 'n') {
                if ($b !== '') { throw $this->truncated(); }
                $res->cols = [];
                $res->oids = [];
                $res->tbls = [];
                $res->lens = [];
                $res->mods = [];
            } elseif ($t === 'C') {
                $this->cmdTag($b, $res);
            } elseif ($t === 'E') {
                if ($res->err === null) { $res->err = $this->parseErr($b, $sql); $this->pendErr = $res->err->text; }
            } elseif ($t === 'S') {
                $this->paramStatus($b);
            } elseif ($t === 'G') {
                // ⚠ the Sync already sent with this batch is IGNORED while the server is in copy-in mode (measured:
                // CopyFail alone leaves us waiting for a ReadyForQuery until the socket timeout), so send a second one
                $copy = 4;
                $this->sendRaw(self::frame('f', "COPY is not supported\0") . self::frame('S', ''));
            } elseif ($t === 'H') {
                $copy = 3;
            } elseif ($t === 'Z') {
                if (\strlen($b) < 1) { throw $this->truncated(); }
                $this->status = $b[0];
                $this->inflight = false;
                // php (measured): HY000, code = PGRES_COPY_IN 4 / PGRES_COPY_OUT 3, empty message; real COPY is later work
                if ($copy > 0) { throw __McPdo::fail('HY000', $copy, true, '', true); }
                return;
            }
        }
    }

    /**
     * Close(S) + Sync, fire and forget: a statement going out of scope must not
     * wait on the network. The ReadyForQuery is read before the next request.
     */
    public function closeStmt(string $name): void
    {
        if ($this->dead) { return; }
        if ($this->busy) { $this->deferred[] = $name; return; }
        $this->busy = true;
        try {
            $this->sendRaw(self::frame('C', 'S' . $name . "\0") . self::frame('S', ''));
            $this->owed++;
        } catch (\Throwable $t) {
            $this->closeSock();
            throw $t;
        } finally {
            $this->busy = false;
        }
    }

    /** Reads the answers of earlier closeStmt() calls. */
    private function settle(): void
    {
        if ($this->dead) { throw $this->ioFail('no connection to the server'); }
        $this->inflight = true;
        foreach ($this->deferred as $n) {
            $this->sendRaw(self::frame('C', 'S' . $n . "\0") . self::frame('S', ''));
            $this->owed++;
        }
        $this->deferred = [];
        $this->pendErr = '';
        while ($this->owed > 0) {
            [$t, $b] = $this->msg();
            if ($t === 'Z') {
                if (\strlen($b) < 1) { throw $this->truncated(); }
                $this->status = $b[0];
                $this->owed--;
            }
        }
    }

    public function parseErr(string $b, string $sql): __McPdoPgErr
    {
        $e = new __McPdoPgErr();
        $o = 0;
        $n = \strlen($b);
        while ($o < $n && $b[$o] !== "\0") {
            $f = $b[$o];
            $z = \strpos($b, "\0", $o + 1);
            if ($z === false) { break; }
            $v = \substr($b, $o + 1, $z - $o - 1);
            $o = $z + 1;
            if ($f === 'S') { $e->sev = $v; }
            elseif ($f === 'C') { $e->code = $v; }
            elseif ($f === 'M') { $e->msg = $v; }
            elseif ($f === 'D') { $e->detail = $v; }
            elseif ($f === 'H') { $e->hint = $v; }
            elseif ($f === 'P') { $e->pos = (int) $v; }
            elseif ($f === 'q') { $e->internal = $v; }
            elseif ($f === 'W') { $e->context = $v; }
        }
        $t = $e->sev . ':  ' . $e->msg . "\n";
        if ($e->pos > 0 && $sql !== '') { $t .= self::linePos($sql, $e->pos); }
        if ($e->detail !== '') { $t .= 'DETAIL:  ' . $e->detail . "\n"; }
        if ($e->hint !== '') { $t .= 'HINT:  ' . $e->hint . "\n"; }
        if ($e->internal !== '') { $t .= 'QUERY:  ' . $e->internal . "\n"; }
        if ($e->context !== '') { $t .= 'CONTEXT:  ' . $e->context . "\n"; }
        $e->text = \rtrim($t, "\n");
        return $e;
    }

    /**
     * libpq's `LINE n: text` + caret, with its 60-column window.
     * ponytail: byte columns, not display width (multibyte text mis-aligns the caret).
     */
    private static function linePos(string $sql, int $pos): string
    {
        $p = $pos - 1;
        $n = \strlen($sql);
        if ($p < 0 || $p > $n) { return ''; }
        $ibeg = 0;
        $line = 1;
        for ($i = 0; $i < $p; $i++) {
            if ($sql[$i] === "\n") { $line++; $ibeg = $i + 1; }
        }
        $iend = \strpos($sql, "\n", $p);
        if ($iend === false) { $iend = $n; }
        $off = $p - $ibeg;
        $pre = '';
        $suf = '';
        if ($iend - $ibeg > 60) {
            if ($off + 10 < 60) {
                $iend = $ibeg + 60;
                $suf = '...';
            } else {
                if ($iend - $p > 10) {
                    $iend = $p + 10;
                    $suf = '...';
                }
                $ibeg = $iend - 60;
                $off = $p - $ibeg;
                $pre = '...';
            }
        }
        $head = 'LINE ' . (string) $line . ': ';
        return $head . $pre . \substr($sql, $ibeg, $iend - $ibeg) . $suf . "\n"
            . \str_repeat(' ', \strlen($head) + \strlen($pre) + $off) . "^\n";
    }

    public function close(): void
    {
        if (!$this->dead && !$this->busy) {
            try { $this->send('X', ''); } catch (PDOException $e) {}
        }
        $this->closeSock();
    }
}

final class __McPdoPg implements __McPdoDrv
{
    public __McPdoPgWire $wire;
    private int $stmtN = 0;
    /** PGSQL_ATTR_DISABLE_PREPARES: every execute() is one unnamed Parse/Bind/Execute, nothing stays server-side. */
    public bool $disablePrepares = false;
    /** ATTR_EMULATE_PREPARES is stored for getAttribute() and otherwise ignored: statements are always server-side. */
    private bool $emulate = false;
    /** @var array<int,string> pg_type.typname by OID, as php's getColumnMeta() looks it up */
    private array $typs = [];
    /** @var array<int,string> pg_class.relname by OID */
    private array $rels = [];

    public function __construct(__McPdoPgWire $wire)
    {
        $this->wire = $wire;
    }

    public function name(): string { return 'pgsql'; }

    public function prepare(string $sql): __McPdoDrvStmt
    {
        return new __McPdoPgStmt($this, $sql);
    }

    /** php's `pdo_stmt_%08x`; the number is visible in the server's own error texts. */
    public function nextStmtName(): string
    {
        $this->stmtN++;
        return 'pdo_stmt_' . \str_pad(\dechex($this->stmtN), 8, '0', \STR_PAD_LEFT);
    }

    public function typName(int $oid): string
    {
        if (!isset($this->typs[$oid])) {
            $this->typs[$oid] = $this->run('select typname from pg_type where oid = ' . (string) $oid)->rows[0][0] ?? '';
        }
        return $this->typs[$oid];
    }

    public function relName(int $oid): string
    {
        if (!isset($this->rels[$oid])) {
            $this->rels[$oid] = $this->run('select relname from pg_class where oid = ' . (string) $oid)->rows[0][0] ?? '';
        }
        return $this->rels[$oid];
    }

    /** Runs $sql, throwing the server's error as php's PDOException. */
    public function run(string $sql): __McPdoPgResult
    {
        $r = $this->wire->simple($sql);
        if ($r->err !== null) {
            throw __McPdo::fail($r->err->code, 7, true, $r->err->text, true);
        }
        return $r;
    }

    public function exec(string $sql): int
    {
        $r = $this->run($sql);
        return \count($r->cols) > 0 ? 0 : $r->affected;
    }

    public function begin(): void { $this->run('BEGIN'); }
    public function commit(): void { $this->run('COMMIT'); }
    public function rollback(): void { $this->run('ROLLBACK'); }
    public function inTransaction(): bool { return $this->wire->status !== 'I'; }

    public function lastInsertId(?string $name): string
    {
        if ($name === null) {
            $r = $this->run('SELECT LASTVAL()');
        } else {
            $r = new __McPdoPgResult();
            $this->wire->extended('', 'SELECT CURRVAL($1)', true, [$name], [], $r);
            if ($r->err !== null) { throw __McPdo::fail($r->err->code, 7, true, $r->err->text, true); }
        }
        $v = $r->rows[0][0] ?? null;
        return $v === null ? '' : $v;
    }

    /**
     * libpq's PQescapeStringConn as php calls it: cut at the first NUL, quotes doubled,
     * backslashes doubled only while standard_conforming_strings is off — and never an
     * E prefix. A string that is not valid in a UTF8 session makes libpq fail and php
     * answer false with no error. PARAM_LOB is a bytea hex literal.
     * ponytail: only UTF8 is validated; other multibyte client encodings pass through.
     */
    public function quote(string $s, int $type): string|false
    {
        if ($type === PDO::PARAM_LOB) { return "'\\x" . \bin2hex($s) . "'"; }
        $z = \strpos($s, "\0");
        if ($z !== false) { $s = \substr($s, 0, $z); }
        if ($this->wire->clientEnc === 'UTF8' && \preg_match('//u', $s) !== 1) { return false; }
        if (!$this->wire->stdStrings) { $s = \str_replace('\\', '\\\\', $s); }
        return "'" . \str_replace("'", "''", $s) . "'";
    }

    public function errorInfo(): array { return __McPdo::triple('', 0, false, '', false); }

    public function setAttr(int $attr, mixed $value): bool
    {
        // php (measured): ATTR_TIMEOUT is a connect-time option only; set afterwards it is a silent false
        if ($attr === PDO::PGSQL_ATTR_DISABLE_PREPARES) { $this->disablePrepares = (bool) $value; return true; }
        if ($attr === PDO::ATTR_EMULATE_PREPARES) { $this->emulate = (bool) $value; return true; }
        if ($attr === PDO::ATTR_PREFETCH) { return true; }
        return false;
    }

    public function getAttr(int $attr): mixed
    {
        if ($attr === PDO::ATTR_SERVER_VERSION) { return $this->wire->serverVersion; }
        // no libpq to ask: the wire protocol version this driver speaks
        if ($attr === PDO::ATTR_CLIENT_VERSION) { return 'manticore-pgwire 3.0'; }
        if ($attr === PDO::ATTR_CONNECTION_STATUS) {
            return $this->wire->dead ? 'Bad connection.' : 'Connection OK; waiting to send.';
        }
        if ($attr === PDO::ATTR_SERVER_INFO) {
            $w = $this->wire;
            return 'PID: ' . (string) $w->pid . '; Client Encoding: ' . $w->clientEnc . '; Is Superuser: ' . $w->isSuper
                . '; Session Authorization: ' . $w->sessAuth . '; Date Style: ' . $w->dateStyle;
        }
        if ($attr === PDO::PGSQL_ATTR_DISABLE_PREPARES) { return $this->disablePrepares; }
        if ($attr === PDO::ATTR_EMULATE_PREPARES) { return $this->emulate; }
        return null;
    }

    public function close(): void { $this->wire->close(); }

    public function __destruct() { $this->wire->close(); }
}

/**
 * One PDOStatement over the extended protocol. Like php, the server-side
 * statement (`pdo_stmt_%08x`, a per-connection counter taken at prepare()) is
 * created by the FIRST execute(), so a syntax error surfaces there, not at
 * prepare(). Every execute() is one Bind/Execute/Sync round trip with the whole
 * result buffered; a second execute() reuses the prepared name.
 */
final class __McPdoPgStmt implements __McPdoDrvStmt
{
    private __McPdoPg $db;
    private string $name;
    private string $sql;
    /** @var array<int,string> one entry per `$n` slot: `?` or `:name` */
    private array $names = [];
    private bool $prepared = false;
    /** Taken from PGSQL_ATTR_DISABLE_PREPARES at prepare(): the unnamed statement is re-parsed by every execute(). */
    private bool $unnamed = false;
    /** @var array<int,?string> by 1-based slot; reset by every execute() (the facade rebinds them all) */
    private array $vals = [];
    /** @var array<int,bool> */
    private array $bin = [];
    private int $maxBound = 0;
    private ?__McPdoPgResult $res = null;
    private int $idx = -1;

    public function __construct(__McPdoPg $db, string $sql)
    {
        $this->db = $db;
        $sc = __McPdoSql::rewritePg($sql);
        $this->sql = $sc->sql;
        $this->names = $sc->names;
        $this->unnamed = $db->disablePrepares;
        $this->name = $this->unnamed ? '' : $db->nextStmtName();
    }

    public function paramCount(): int { return \count($this->names); }

    /** An unknown name is php core's HY093 carrying the name, not a silent false. */
    public function paramIndex(string $name): int
    {
        foreach ($this->names as $i => $n) {
            if ($n === $name) { return $i + 1; }
        }
        // `:1` is php's spelling of positional slot 1: answer 0 so the facade falls back to the number
        if (\ctype_digit(\substr($name, 1))) { return 0; }
        throw __McPdo::fail('HY093', 0, false, $name, true);
    }

    /**
     * php's pdo_pgsql parameter conversion (measured): NULL and PARAM_NULL send
     * NULL; a LOB or a stream goes in binary, whole; PARAM_BOOL turns a bool or
     * an int into t/f; everything else is its string form, cut at the first NUL
     * (the text format is a C string); PARAM_INT with a bool is 1/0.
     */
    public function bind(int $idx, mixed $value, int $type): void
    {
        if ($idx < 1 || $idx > \count($this->names)) {
            throw __McPdo::fail('HY093', 0, false, 'parameter was not defined', true);
        }
        if ($idx > $this->maxBound) { $this->maxBound = $idx; }
        $this->bin[$idx] = false;
        if ($value === null || $type === PDO::PARAM_NULL) {
            $this->vals[$idx] = null;
            return;
        }
        if (\is_resource($value)) {
            $this->vals[$idx] = (string) \stream_get_contents($value);
            $this->bin[$idx] = true;
            return;
        }
        if ($type === PDO::PARAM_LOB) {
            $this->vals[$idx] = (string) $value;
            $this->bin[$idx] = true;
            return;
        }
        if (\is_bool($value)) {
            $s = $type === PDO::PARAM_BOOL ? ($value ? 't' : 'f') : ($type === PDO::PARAM_INT ? ($value ? '1' : '0') : ($value ? '1' : ''));
        } elseif (\is_int($value) && $type === PDO::PARAM_BOOL) {
            $s = $value !== 0 ? 't' : 'f';
        } else {
            $s = (string) $value;
        }
        $z = \strpos($s, "\0");
        $this->vals[$idx] = $z === false ? $s : \substr($s, 0, $z);
    }

    public function execute(): void
    {
        /** @var array<int,?string> */
        $params = [];
        /** @var array<int,bool> */
        $bin = [];
        for ($i = 1; $i <= $this->maxBound; $i++) {
            $params[] = $this->vals[$i] ?? null;
            $bin[] = $this->bin[$i] ?? false;
        }
        $this->vals = [];
        $this->bin = [];
        $this->maxBound = 0;

        $r = new __McPdoPgResult();
        $prev = $this->res;
        if ($this->prepared && !$this->unnamed && $prev !== null) {
            $r->cols = $prev->cols;
            $r->oids = $prev->oids;
            $r->tbls = $prev->tbls;
            $r->lens = $prev->lens;
            $r->mods = $prev->mods;
        }
        $this->db->wire->extended($this->name, $this->sql, !$this->prepared || $this->unnamed, $params, $bin, $r);
        if ($r->parsed) { $this->prepared = true; }
        $this->res = $r;
        $this->idx = -1;
        if ($r->err !== null) {
            throw __McPdo::fail($r->err->code, 7, true, $r->err->text, true);
        }
    }

    public function step(): bool
    {
        if ($this->res === null) { return false; }
        if ($this->idx + 1 >= \count($this->res->rows)) { return false; }
        $this->idx++;
        return true;
    }

    public function columnCount(): int { return $this->res === null ? 0 : \count($this->res->cols); }
    public function columnName(int $i): string { return $this->res === null ? '' : $this->res->cols[$i]; }

    /**
     * php's key order: `pgsql:oid`, `pgsql:table_oid`, `table` (only for a plain
     * table column), `native_type` (pg_type.typname), `pdo_type`, `name`, `len`
     * (typlen), `precision` (atttypmod, raw).
     *
     * @return array<string,mixed>
     */
    public function columnMeta(int $i): array
    {
        if ($this->res === null) { return []; }
        $oid = $this->res->oids[$i];
        $tbl = $this->res->tbls[$i];
        /** @var array<string,mixed> */
        $m = ['pgsql:oid' => $oid, 'pgsql:table_oid' => $tbl];
        if ($tbl > 0) { $m['table'] = $this->db->relName($tbl); }
        $m['native_type'] = $this->db->typName($oid);
        $m['pdo_type'] = $oid === 16 ? PDO::PARAM_BOOL
            : (($oid === 20 || $oid === 21 || $oid === 23) ? PDO::PARAM_INT
            : (($oid === 17 || $oid === 26) ? PDO::PARAM_LOB : PDO::PARAM_STR));
        $m['name'] = $this->res->cols[$i];
        $m['len'] = $this->res->lens[$i];
        $m['precision'] = $this->res->mods[$i];
        return $m;
    }

    /**
     * php's pdo_pgsql typing: bool, int2/4/8 and oid native, float4/8 as float
     * (`NaN` / `Infinity` spelled the way the server prints them), bytea as a
     * stream, everything else — numeric, dates, json, uuid — as the text.
     * ponytail: bytea assumes bytea_output=hex (the server default).
     */
    public function columnValue(int $i): mixed
    {
        if ($this->res === null) { return null; }
        $v = $this->res->rows[$this->idx][$i];
        if ($v === null) { return null; }
        $o = $this->res->oids[$i];
        if ($o === 25 || $o === 1043) { return $v; }
        if ($o === 23 || $o === 20 || $o === 21 || $o === 26) {
            $d = $v !== '' && $v[0] === '-' ? \substr($v, 1) : $v;
            $n = \strlen($d);
            if ($n > 19 || !\ctype_digit($d)) { throw self::badValue('integer'); }
            // 19 digits can still overflow: (int) would saturate silently
            if ($n === 19 && \strcmp($d, $d === $v ? '9223372036854775807' : '9223372036854775808') > 0) { throw self::badValue('integer'); }
            return (int) $v;
        }
        if ($o === 16) { return $v === 't'; }
        if ($o === 701 || $o === 700) {
            if ($v === 'NaN') { return \NAN; }
            if ($v === 'Infinity') { return \INF; }
            if ($v === '-Infinity') { return -\INF; }
            // is_numeric() also takes surrounding whitespace, which no server prints
            if (!\is_numeric($v) || \ctype_space($v[0]) || \ctype_space($v[\strlen($v) - 1])) { throw self::badValue('float'); }
            return (float) $v;
        }
        if ($o === 17) {
            $f = \fopen('php://memory', 'w+');
            \fwrite($f, self::unbytea($v));
            \rewind($f);
            return $f;
        }
        return $v;
    }

    /**
     * A malformed value from the server is HY000, NOT a closed connection: the
     * DataRow was fully consumed and framed correctly, so the protocol is still
     * in sync and the statement (and connection) stay usable.
     */
    private static function badValue(string $what): PDOException
    {
        return __McPdo::fail('HY000', 0, false, 'invalid ' . $what . ' value from server', true);
    }

    /** bytea text output: hex (`\xdeadbeef`, the default) or escape (`a\000b\\`, bytea_output=escape). */
    private static function unbytea(string $v): string
    {
        $n = \strlen($v);
        if ($n >= 2 && $v[0] === '\\' && $v[1] === 'x') {
            $h = \substr($v, 2);
            if ($h === '') { return ''; }
            if (\strlen($h) % 2 !== 0 || !\ctype_xdigit($h)) { throw self::badValue('bytea'); }
            return (string) \hex2bin($h);
        }
        if (\strpos($v, '\\') === false) { return $v; }
        $out = '';
        $i = 0;
        while ($i < $n) {
            $c = $v[$i];
            if ($c !== '\\') {
                $j = \strpos($v, '\\', $i);
                $j = $j === false ? $n : $j;
                $out .= \substr($v, $i, $j - $i);
                $i = $j;
            } elseif ($i + 1 < $n && $v[$i + 1] === '\\') {
                $out .= '\\';
                $i += 2;
            } elseif ($i + 3 < $n && $v[$i + 1] >= '0' && $v[$i + 1] <= '3' && $v[$i + 2] >= '0' && $v[$i + 2] <= '7'
                      && $v[$i + 3] >= '0' && $v[$i + 3] <= '7') {
                $out .= \chr(((\ord($v[$i + 1]) - 48) << 6) | ((\ord($v[$i + 2]) - 48) << 3) | (\ord($v[$i + 3]) - 48));
                $i += 4;
            } else {
                throw self::badValue('bytea');
            }
        }
        return $out;
    }

    public function rowCount(): int { return $this->res === null ? 0 : $this->res->affected; }

    public function closeCursor(): void
    {
        if ($this->res !== null) { $this->idx = \count($this->res->rows); }
    }

    public function close(): void
    {
        if ($this->prepared && !$this->unnamed) {
            $this->prepared = false;
            try { $this->db->wire->closeStmt($this->name); } catch (PDOException $e) {}
        }
        $this->res = null;
    }

    public function errorInfo(): array { return __McPdo::triple('', 0, false, '', false); }

    public function __destruct() { $this->close(); }
}

function __mc_pdo_pgsql_open(string $rest, array $options, ?string $user, ?string $pw): __McPdoDrv
{
    $p = __McPdo::parseParams($rest);
    $host = isset($p['host']) && $p['host'] !== '' ? $p['host'] : 'localhost';
    $port = isset($p['port']) ? (int) $p['port'] : 5432;
    // ⚠ php (measured): a user= / password= in the DSN beats the constructor argument.
    if (isset($p['user'])) { $user = $p['user']; }
    if ($user === null) { $user = (string) (\getenv('USER') ?: 'postgres'); }
    if (isset($p['password'])) { $pw = $p['password']; }
    $db = isset($p['dbname']) ? $p['dbname'] : $user;
    $ssl = isset($p['sslmode']) ? $p['sslmode'] : 'prefer';
    if ($ssl === 'require' || $ssl === 'verify-ca' || $ssl === 'verify-full') {
        throw __McPdo::connectFail('08006', 7, 'sslmode=' . $ssl . ' is not supported yet');
    }
    $timeout = isset($options[PDO::ATTR_TIMEOUT]) ? (float) $options[PDO::ATTR_TIMEOUT] : 30.0;

    $wire = new __McPdoPgWire($host, $port, $timeout);
    try {
        $wire->startup($user, $db, []);
        $wire->auth($user, $pw);
    } catch (PDOException $e) {
        $wire->close();
        throw $e;
    }
    return new __McPdoPg($wire);
}
