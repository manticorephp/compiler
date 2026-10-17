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
    /** @var array<int,array<int,?string>> */
    public array $rows = [];
    public string $tag = '';
    public int $affected = 0;
    public ?__McPdoPgErr $err = null;
}

final class __McPdoPgWire
{
    public bool $dead = false;
    /** ReadyForQuery status: I idle, T in a transaction, E failed transaction. */
    public string $status = 'I';
    public string $serverVersion = '';
    /** libpq's prefix for every failure while connecting. */
    public string $where = '';
    private bool $connecting = true;
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
        $this->setTimeout($timeout);
    }

    public function setTimeout(float $t): void
    {
        $sec = (int) $t;
        \stream_set_timeout($this->s, $sec, (int) (($t - $sec) * 1000000));
    }

    /** @param array<string,string> $params extra startup parameters */
    public function startup(string $user, string $db, array $params): void
    {
        $p = "user\0" . $user . "\0database\0" . $db . "\0";
        foreach ($params as $k => $v) { $p .= $k . "\0" . $v . "\0"; }
        $this->send('', self::be32(196608) . $p . "\0");
    }

    public function send(string $type, string $body): void
    {
        if ($this->dead) { throw $this->ioFail('connection is closed'); }
        $m = $type . self::be32(\strlen($body) + 4) . $body;
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
        if (\strlen($this->buf) - $p < 1 + $len) {
            $this->fill(1 + $len);
            $p = 0;
        }
        $body = \substr($this->buf, $p + 5, $len - 4);
        $this->pos = $p + 1 + $len;
        return [$t, $body];
    }

    private function ioFail(string $m): PDOException
    {
        $this->dead = true;
        return $this->connecting
            ? __McPdo::connectFail('08006', 7, $this->where . $m)
            : __McPdo::fail('08006', 7, true, $m, true);
    }

    /** pack('N') without pack(): the binary prelude is gated on the PROGRAM calling it. */
    private static function be32(int $n): string
    {
        return \chr(($n >> 24) & 255) . \chr(($n >> 16) & 255) . \chr(($n >> 8) & 255) . \chr($n & 255);
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
        $iter = 0;
        foreach (\explode(',', $serverFirst) as $p) {
            if (\strlen($p) < 2 || $p[1] !== '=') { continue; }
            if ($p[0] === 'r') { $r = \substr($p, 2); }
            elseif ($p[0] === 's') { $s = \substr($p, 2); }
            elseif ($p[0] === 'i') { $iter = (int) \substr($p, 2); }
        }
        if (\strlen($r) <= \strlen($clientNonce) || \substr($r, 0, \strlen($clientNonce)) !== $clientNonce
            || $s === '' || $iter < 1) {
            throw __McPdo::connectFail('08006', 7, 'invalid SCRAM server-first-message');
        }
        $salted = \hash_pbkdf2('sha256', $pw, \base64_decode($s), $iter, 32, true);
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
        $this->dead = true;
        return __McPdo::connectFail('08006', 7, $this->where . $text);
    }

    /** Runs the authentication exchange up to the first ReadyForQuery. */
    public function auth(string $user, ?string $pw): void
    {
        while (true) {
            [$t, $b] = $this->msg();
            if ($t === 'E') { throw $this->startFail($this->parseErr($b, '')->text); }
            if ($t === 'S') { $this->paramStatus($b); continue; }
            if ($t === 'Z') {
                $this->status = $b[0];
                $this->connecting = false;
                return;
            }
            if ($t !== 'R') { continue; }
            $code = self::i32($b, 0);
            if ($code === 0) { continue; }
            if ($code === 3 || $code === 5 || $code === 10) {
                if ($pw === null) { throw $this->startFail('fe_sendauth: no password supplied'); }
            }
            if ($code === 3) {
                $this->send('p', $pw . "\0");
            } elseif ($code === 5) {
                $this->send('p', 'md5' . \md5(\md5($pw . $user) . \substr($b, 4, 4)) . "\0");
            } elseif ($code === 10) {
                if (\strpos($b, "SCRAM-SHA-256\0") === false) {
                    throw $this->startFail("none of the server's SASL authentication mechanisms are supported");
                }
                $this->nonce = \base64_encode(\random_bytes(18));
                $init = 'n,,n=,r=' . $this->nonce;
                $this->send('p', "SCRAM-SHA-256\0" . self::be32(\strlen($init)) . $init);
            } elseif ($code === 11) {
                [$final, $this->serverSig] = self::scram($pw === null ? '' : $pw, $this->nonce, \substr($b, 4), '');
                $this->send('p', $final);
            } elseif ($code === 12) {
                $v = \substr($b, 4);
                if (\substr($v, 0, 2) !== 'v=' || !\hash_equals($this->serverSig, \substr($v, 2))) {
                    throw $this->startFail('SCRAM-SHA-256 server signature mismatch');
                }
            } else {
                throw $this->startFail('authentication method ' . (string) $code . ' not supported');
            }
        }
    }

    private function paramStatus(string $b): void
    {
        $z = \strpos($b, "\0");
        if ($z !== false && \substr($b, 0, $z) === 'server_version') {
            $this->serverVersion = \substr($b, $z + 1, \strlen($b) - $z - 2);
        }
    }

    /** Runs one query string over the simple protocol. Never throws on a server error. */
    public function simple(string $sql): __McPdoPgResult
    {
        $this->send('Q', $sql . "\0");
        $res = new __McPdoPgResult();
        $seenT = false;
        while (true) {
            [$t, $b] = $this->msg();
            if ($t === 'D') {
                $n = (\ord($b[0]) << 8) | \ord($b[1]);
                $o = 2;
                /** @var array<int,?string> */
                $row = [];
                for ($i = 0; $i < $n; $i++) {
                    $l = (\ord($b[$o]) << 24) | (\ord($b[$o + 1]) << 16) | (\ord($b[$o + 2]) << 8) | \ord($b[$o + 3]);
                    $o += 4;
                    if ($l === 0xFFFFFFFF) { $row[] = null; continue; }
                    $row[] = \substr($b, $o, $l);
                    $o += $l;
                }
                $res->rows[] = $row;
            } elseif ($t === 'T') {
                $res->cols = [];
                $res->oids = [];
                $res->rows = [];
                $seenT = true;
                $n = (\ord($b[0]) << 8) | \ord($b[1]);
                $o = 2;
                for ($i = 0; $i < $n; $i++) {
                    $z = \strpos($b, "\0", $o);
                    $res->cols[] = \substr($b, $o, $z - $o);
                    $res->oids[] = self::i32($b, $z + 7);
                    $o = $z + 19;
                }
            } elseif ($t === 'C') {
                if (!$seenT) {
                    $res->cols = [];
                    $res->oids = [];
                    $res->rows = [];
                }
                $seenT = false;
                $res->tag = \substr($b, 0, \strlen($b) - 1);
                $sp = \strrpos($res->tag, ' ');
                $res->affected = $sp === false ? 0 : (int) \substr($res->tag, $sp + 1);
            } elseif ($t === 'E') {
                if ($res->err === null) { $res->err = $this->parseErr($b, $sql); }
            } elseif ($t === 'S') {
                $this->paramStatus($b);
            } elseif ($t === 'Z') {
                $this->status = $b[0];
                return $res;
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
        if ($this->dead) { return; }
        $this->dead = true;
        try { $this->send('X', ''); } catch (PDOException $e) {}
        \fclose($this->s);
    }
}

final class __McPdoPg implements __McPdoDrv
{
    public __McPdoPgWire $wire;

    public function __construct(__McPdoPgWire $wire)
    {
        $this->wire = $wire;
    }

    public function name(): string { return 'pgsql'; }

    public function prepare(string $sql): __McPdoDrvStmt
    {
        return new __McPdoPgStmt($this, $sql);
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
        $r = $this->run($name === null ? 'SELECT LASTVAL()' : 'SELECT CURRVAL(' . $this->quote($name, PDO::PARAM_STR) . ')');
        $v = $r->rows[0][0] ?? null;
        return $v === null ? '' : $v;
    }

    public function quote(string $s, int $type): string
    {
        return "'" . \str_replace("'", "''", $s) . "'";
    }

    public function errorInfo(): array { return __McPdo::triple('', 0, false, '', false); }

    public function setAttr(int $attr, mixed $value): bool
    {
        if ($attr === PDO::ATTR_TIMEOUT) {
            $this->wire->setTimeout((float) $value);
            return true;
        }
        return false;
    }

    public function getAttr(int $attr): mixed
    {
        if ($attr === PDO::ATTR_SERVER_VERSION) { return $this->wire->serverVersion; }
        return null;
    }

    public function close(): void { $this->wire->close(); }

    public function __destruct() { $this->wire->close(); }
}

/**
 * A statement over the simple protocol: whole result buffered at execute().
 * Parameterless only — Task 4 puts the extended protocol behind prepare() for
 * statements with placeholders and keeps this class for the rest.
 */
final class __McPdoPgStmt implements __McPdoDrvStmt
{
    private __McPdoPg $db;
    private string $sql;
    private int $nparams = 0;
    private ?__McPdoPgResult $res = null;
    private int $idx = -1;

    public function __construct(__McPdoPg $db, string $sql)
    {
        $this->db = $db;
        $sc = __McPdoSql::scan($sql, 'pgsql');
        $this->sql = $sc->sql;
        $this->nparams = \count($sc->names);
    }

    public function paramCount(): int { return $this->nparams; }
    public function paramIndex(string $name): int { return 0; }

    public function bind(int $idx, mixed $value, int $type): void
    {
        throw new PDOException('pgsql prepared statements with parameters are not implemented');
    }

    public function execute(): void
    {
        if ($this->nparams > 0) {
            throw new PDOException('pgsql prepared statements with parameters are not implemented');
        }
        $this->res = $this->db->run($this->sql);
        $this->idx = -1;
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

    public function columnMeta(int $i): array
    {
        /** @var array<string,mixed> */
        $m = ['name' => $this->columnName($i), 'len' => -1, 'precision' => 0, 'pdo_type' => PDO::PARAM_STR];
        return $m;
    }

    /** php's pdo_pgsql typing: bool, int2/4/8 and oid come back native, the rest as text. */
    public function columnValue(int $i): mixed
    {
        if ($this->res === null) { return null; }
        $v = $this->res->rows[$this->idx][$i];
        if ($v === null) { return null; }
        $o = $this->res->oids[$i];
        if ($o === 16) { return $v === 't'; }
        if ($o === 20 || $o === 21 || $o === 23 || $o === 26) { return (int) $v; }
        return $v;
    }

    public function rowCount(): int { return $this->res === null ? 0 : $this->res->affected; }

    public function closeCursor(): void
    {
        if ($this->res !== null) { $this->idx = \count($this->res->rows); }
    }

    public function close(): void { $this->res = null; }

    public function errorInfo(): array { return __McPdo::triple('', 0, false, '', false); }
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
    $wire->startup($user, $db, []);
    $wire->auth($user, $pw);
    return new __McPdoPg($wire);
}
