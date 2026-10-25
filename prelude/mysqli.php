<?php

/** ext/mysqli skeleton: every Zend symbol declared with its Zend signature; bodies land with the implementation. Gated on a `mysqli*` mention (see Main.php). */

const MYSQLI_ASSOC = 1;
const MYSQLI_ASYNC = 8;
const MYSQLI_AUTO_INCREMENT_FLAG = 512;
const MYSQLI_BINARY_FLAG = 128;
const MYSQLI_BLOB_FLAG = 16;
const MYSQLI_BOTH = 3;
const MYSQLI_CLIENT_CAN_HANDLE_EXPIRED_PASSWORDS = 4194304;
const MYSQLI_CLIENT_COMPRESS = 32;
const MYSQLI_CLIENT_FOUND_ROWS = 2;
const MYSQLI_CLIENT_IGNORE_SPACE = 256;
const MYSQLI_CLIENT_INTERACTIVE = 1024;
const MYSQLI_CLIENT_NO_SCHEMA = 16;
const MYSQLI_CLIENT_SSL = 2048;
const MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT = 64;
const MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT = 1073741824;
const MYSQLI_CURSOR_TYPE_NO_CURSOR = 0;
const MYSQLI_CURSOR_TYPE_READ_ONLY = 1;
const MYSQLI_DATA_TRUNCATED = 101;
const MYSQLI_DEBUG_TRACE_ENABLED = 0;
const MYSQLI_ENUM_FLAG = 256;
const MYSQLI_GROUP_FLAG = 32768;
const MYSQLI_INIT_COMMAND = 3;
const MYSQLI_IS_MARIADB = false;
const MYSQLI_MULTIPLE_KEY_FLAG = 8;
const MYSQLI_NOT_NULL_FLAG = 1;
const MYSQLI_NO_DATA = 100;
const MYSQLI_NO_DEFAULT_VALUE_FLAG = 4096;
const MYSQLI_NUM = 2;
const MYSQLI_NUM_FLAG = 32768;
const MYSQLI_ON_UPDATE_NOW_FLAG = 8192;
const MYSQLI_OPT_CAN_HANDLE_EXPIRED_PASSWORDS = 37;
const MYSQLI_OPT_CONNECT_TIMEOUT = 0;
const MYSQLI_OPT_INT_AND_FLOAT_NATIVE = 201;
const MYSQLI_OPT_LOAD_DATA_LOCAL_DIR = 43;
const MYSQLI_OPT_LOCAL_INFILE = 8;
const MYSQLI_OPT_NET_CMD_BUFFER_SIZE = 202;
const MYSQLI_OPT_NET_READ_BUFFER_SIZE = 203;
const MYSQLI_OPT_READ_TIMEOUT = 11;
const MYSQLI_OPT_SSL_VERIFY_SERVER_CERT = 21;
const MYSQLI_PART_KEY_FLAG = 16384;
const MYSQLI_PRI_KEY_FLAG = 2;
const MYSQLI_READ_DEFAULT_FILE = 4;
const MYSQLI_READ_DEFAULT_GROUP = 5;
const MYSQLI_REFRESH_BACKUP_LOG = 2097152;
const MYSQLI_REFRESH_GRANT = 1;
const MYSQLI_REFRESH_HOSTS = 8;
const MYSQLI_REFRESH_LOG = 2;
const MYSQLI_REFRESH_MASTER = 128;
const MYSQLI_REFRESH_REPLICA = 64;
const MYSQLI_REFRESH_SLAVE = 64;
const MYSQLI_REFRESH_STATUS = 16;
const MYSQLI_REFRESH_TABLES = 4;
const MYSQLI_REFRESH_THREADS = 32;
const MYSQLI_REPORT_ALL = 255;
const MYSQLI_REPORT_ERROR = 1;
const MYSQLI_REPORT_INDEX = 4;
const MYSQLI_REPORT_OFF = 0;
const MYSQLI_REPORT_STRICT = 2;
const MYSQLI_SERVER_PS_OUT_PARAMS = 4096;
const MYSQLI_SERVER_PUBLIC_KEY = 35;
const MYSQLI_SERVER_QUERY_NO_GOOD_INDEX_USED = 16;
const MYSQLI_SERVER_QUERY_NO_INDEX_USED = 32;
const MYSQLI_SERVER_QUERY_WAS_SLOW = 2048;
const MYSQLI_SET_CHARSET_NAME = 7;
const MYSQLI_SET_FLAG = 2048;
const MYSQLI_STMT_ATTR_CURSOR_TYPE = 1;
const MYSQLI_STMT_ATTR_UPDATE_MAX_LENGTH = 0;
const MYSQLI_STORE_RESULT = 0;
const MYSQLI_STORE_RESULT_COPY_DATA = 16;
const MYSQLI_TIMESTAMP_FLAG = 1024;
const MYSQLI_TRANS_COR_AND_CHAIN = 1;
const MYSQLI_TRANS_COR_AND_NO_CHAIN = 2;
const MYSQLI_TRANS_COR_NO_RELEASE = 8;
const MYSQLI_TRANS_COR_RELEASE = 4;
const MYSQLI_TRANS_START_READ_ONLY = 4;
const MYSQLI_TRANS_START_READ_WRITE = 2;
const MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT = 1;
const MYSQLI_TYPE_BIT = 16;
const MYSQLI_TYPE_BLOB = 252;
const MYSQLI_TYPE_CHAR = 1;
const MYSQLI_TYPE_DATE = 10;
const MYSQLI_TYPE_DATETIME = 12;
const MYSQLI_TYPE_DECIMAL = 0;
const MYSQLI_TYPE_DOUBLE = 5;
const MYSQLI_TYPE_ENUM = 247;
const MYSQLI_TYPE_FLOAT = 4;
const MYSQLI_TYPE_GEOMETRY = 255;
const MYSQLI_TYPE_INT24 = 9;
const MYSQLI_TYPE_JSON = 245;
const MYSQLI_TYPE_LONG = 3;
const MYSQLI_TYPE_LONGLONG = 8;
const MYSQLI_TYPE_LONG_BLOB = 251;
const MYSQLI_TYPE_MEDIUM_BLOB = 250;
const MYSQLI_TYPE_NEWDATE = 14;
const MYSQLI_TYPE_NEWDECIMAL = 246;
const MYSQLI_TYPE_NULL = 6;
const MYSQLI_TYPE_SET = 248;
const MYSQLI_TYPE_SHORT = 2;
const MYSQLI_TYPE_STRING = 254;
const MYSQLI_TYPE_TIME = 11;
const MYSQLI_TYPE_TIMESTAMP = 7;
const MYSQLI_TYPE_TINY = 1;
const MYSQLI_TYPE_TINY_BLOB = 249;
const MYSQLI_TYPE_VAR_STRING = 253;
const MYSQLI_TYPE_VECTOR = 242;
const MYSQLI_TYPE_YEAR = 13;
const MYSQLI_UNIQUE_KEY_FLAG = 4;
const MYSQLI_UNSIGNED_FLAG = 32;
const MYSQLI_USE_RESULT = 1;
const MYSQLI_ZEROFILL_FLAG = 64;

/**
 * The state behind one mysqli object, on the pdo_mysql wire (__McPdoMyWire): the connection, the options set before
 * connecting, the last error and the counters the read-only properties report. mysqli's Zend properties are virtual
 * (hooks reading this object), so the object itself holds nothing but mysqli::$__mc; mysqli_result and mysqli_stmt
 * reach the connection through it.
 */
final class __McMysqliConn
{
    /** Zend's MYSQLI_STATUS_*: never connected (or no longer), mysqli_init()ed, connected. */
    public const CLOSED = 0;
    public const INIT = 1;
    public const VALID = 2;

    /** mysqli_report() and mysqli_driver::$report_mode: one process-wide mode, Zend's default MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT. */
    public static int $mode = 3;
    /** The LAST connect attempt of the process: mysqli_connect_errno() and every object's connect_errno read the same. */
    public static int $connErrno = 0;
    public static ?string $connError = null;

    /**
     * mysqlnd's charset table, first entry per name: number|collation|min length|max length|comment. Packed strings,
     * not nested arrays: a mixed-value array loses its element type.
     * @var array<string,string>
     */
    private const CHARSETS = [
        'big5' => '1|big5_chinese_ci|1|2|', 'dec8' => '3|dec8_swedish_ci|1|1|', 'cp850' => '4|cp850_general_ci|1|1|',
        'hp8' => '6|hp8_english_ci|1|1|', 'koi8r' => '7|koi8r_general_ci|1|1|', 'latin1' => '8|latin1_swedish_ci|1|1|',
        'latin2' => '9|latin2_general_ci|1|1|', 'swe7' => '10|swe7_swedish_ci|1|1|', 'ascii' => '11|ascii_general_ci|1|1|',
        'ujis' => '12|ujis_japanese_ci|1|3|', 'sjis' => '13|sjis_japanese_ci|1|2|', 'hebrew' => '16|hebrew_general_ci|1|1|',
        'filename' => '17|filename|1|5|', 'tis620' => '18|tis620_thai_ci|1|1|', 'euckr' => '19|euckr_korean_ci|1|2|',
        'koi8u' => '22|koi8u_general_ci|1|1|', 'gb2312' => '24|gb2312_chinese_ci|1|2|', 'greek' => '25|greek_general_ci|1|1|',
        'cp1250' => '26|cp1250_general_ci|1|1|', 'gbk' => '28|gbk_chinese_ci|1|2|', 'latin5' => '30|latin5_turkish_ci|1|1|',
        'armscii8' => '32|armscii8_general_ci|1|1|', 'utf8' => '33|utf8_general_ci|1|3|UTF-8 Unicode',
        'ucs2' => '35|ucs2_general_ci|2|2|UCS-2 Unicode', 'cp866' => '36|cp866_general_ci|1|1|',
        'keybcs2' => '37|keybcs2_general_ci|1|1|', 'macce' => '38|macce_general_ci|1|1|', 'macroman' => '39|macroman_general_ci|1|1|',
        'cp852' => '40|cp852_general_ci|1|1|', 'latin7' => '41|latin7_general_ci|1|1|', 'utf8mb4' => '45|utf8mb4_general_ci|1|4|UTF-8 Unicode',
        'cp1251' => '51|cp1251_general_ci|1|1|', 'utf16' => '54|utf16_general_ci|2|4|UTF-16 Unicode',
        'utf16le' => '56|utf16le_general_ci|2|4|UTF-16LE Unicode', 'cp1256' => '57|cp1256_general_ci|1|1|',
        'cp1257' => '59|cp1257_general_ci|1|1|', 'utf32' => '60|utf32_general_ci|4|4|UTF-32 Unicode', 'binary' => '63|binary|1|1|',
        'geostd8' => '92|geostd8_general_ci|1|1|', 'cp932' => '95|cp932_japanese_ci|1|2|', 'eucjpms' => '97|eucjpms_japanese_ci|1|3|',
        'gb18030' => '248|gb18030_chinese_ci|1|4|',
    ];
    /** What a MySQL 8 server's handshake gets for utf8mb4 (mysqlnd), outside the by-name table. */
    private const UTF8MB4_0900 = '255|utf8mb4_0900_ai_ci|1|4|';

    /** The 18 properties in Zend's own (registration) order, as var_dump lists them. */
    public const PROPS = ['affected_rows', 'client_info', 'client_version', 'connect_errno', 'connect_error', 'errno', 'error',
        'error_list', 'field_count', 'host_info', 'info', 'insert_id', 'server_info', 'server_version', 'sqlstate',
        'protocol_version', 'thread_id', 'warning_count'];

    public int $st = self::INIT;
    public ?__McPdoMyWire $wire = null;
    public string $hostInfo = '';
    public string $serverInfo = '';
    public int $serverVersion = 0;
    public int $threadId = 0;
    /** The handshake's protocol version: the wire refuses anything but 10. */
    public int $protocolVersion = 10;
    /** The client charset mysqlnd escapes with (character_set_name()), and its table row. */
    public string $charset = 'utf8mb4';
    public string $csRow = '45|utf8mb4_general_ci|1|4|UTF-8 Unicode';

    public int $errno = 0;
    public string $error = '';
    public string $sqlstate = '00000';
    /** @var array<int,array<string,mixed>> */
    public array $errorList = [];

    public int|string $affectedRows = 0;
    public int|string $insertId = 0;
    public ?string $info = null;
    public int $warningCount = 0;
    public int $fieldCount = 0;

    /** Zend's text for a command sent while a result is still being read or waits to be taken. */
    public const OOS = "Commands out of sync; you can't run this command now";
    /** A result set whose header was read (real_query, next_result) and that store_result() / use_result() has not taken yet. */
    public ?__McPdoMyResult $pend = null;
    /** The results the server sent behind the current one (CALL, multi_query), not yet reached by next_result(). */
    public ?__McPdoMyResult $chain = null;
    /** The unbuffered result being read: the wire is mid-result until it ends or is freed. */
    public ?__McMysqliRs $open = null;

    /** options() / ssl_set(), applied by the next connect */
    public int $connectTimeout = 0;
    public int $readTimeout = 0;
    /** @var array<int,string> */
    public array $initCmds = [];
    public string $optCharset = '';
    public bool $intAndFloatNative = false;
    public bool $sslWanted = false;
    public string $sslKey = '';
    public string $sslCert = '';
    public string $sslCa = '';
    public string $sslCaPath = '';
    public ?string $sslCipher = null;

    public static function of(mysqli $m): self
    {
        return $m->__mcConn();
    }

    public static function ro(object $o, string $prop): void
    {
        throw new Error('Cannot write read-only property ' . \get_class($o) . '::$' . $prop);
    }

    public static function clientInfo(): string { return 'mysqlnd ' . \PHP_VERSION; }

    /** errno / error: any handle that is not closed. */
    public function alive(): self
    {
        if ($this->st === self::CLOSED) { throw new Error('mysqli object is already closed'); }
        return $this;
    }

    /** affected_rows / error_list: a connected handle only. */
    public function connected(): self
    {
        if ($this->st !== self::VALID) { throw new Error('Property access is not allowed yet'); }
        return $this;
    }

    /** Every other connection property. */
    public function prop(): self
    {
        if ($this->st === self::VALID) { return $this; }
        throw new Error($this->st === self::INIT ? 'Property access is not allowed yet' : 'mysqli object is already closed');
    }

    /** A method that needs the connection (MYSQLI_FETCH_RESOURCE_CONN). */
    public function link(): self
    {
        if ($this->st === self::VALID) { return $this; }
        throw new Error($this->st === self::INIT ? 'mysqli object is not fully initialized' : 'mysqli object is already closed');
    }

    public function liveWire(): __McPdoMyWire
    {
        $w = $this->link()->wire;
        if ($w === null) { throw new Error('mysqli object is already closed'); }
        return $w;
    }

    /** A server command starts: the previous error is forgotten (client-side failures do not reset it). */
    public function clear(): void
    {
        $this->errno = 0;
        $this->error = '';
        $this->sqlstate = '00000';
        $this->errorList = [];
    }

    /** Records an error; throws mysqli_sql_exception iff the report mode has both ERROR and STRICT, else returns false. */
    public function fail(int $no, string $state, string $msg): false
    {
        $this->errno = $no;
        $this->error = $msg;
        $this->sqlstate = $state;
        $this->errorList[] = ['errno' => $no, 'sqlstate' => $state, 'error' => $msg];
        if ((self::$mode & 3) === 3) { throw mysqli_sql_exception::__mcNew($msg, $no, $state); }
        return false;
    }

    /** A server ERR packet (null = OK: true). */
    public function cmd(?__McPdoMyErr $e): bool
    {
        return $e === null ? true : $this->fail($e->no, $e->state, $e->msg);
    }

    /** A failure the wire raised (I/O, protocol, out of sync). */
    public function wireFail(PDOException $e): false
    {
        return $this->fail($e->driverCode, $e->sqlstate === '' ? 'HY000' : $e->sqlstate, $e->driverMsg);
    }

    /** A result is waiting to be taken, read or advanced: no command may start (mysqlnd's state != READY). */
    public function busy(): bool
    {
        return $this->pend !== null || $this->open !== null || $this->chain !== null;
    }

    /** Forgets every result in flight (the connection is going away). */
    public function dropResults(): void
    {
        $o = $this->open;
        $this->open = null;
        $this->pend = null;
        $this->chain = null;
        if ($o !== null) { $o->c = null; }
    }

    /**
     * A server command starts: the previous error is forgotten, affected_rows reads -1 until the reply says otherwise,
     * and a connection with a result in flight refuses (2014, thrown iff the report mode says so).
     */
    public function begin(): bool
    {
        $this->clear();
        $this->affectedRows = -1;
        if (!$this->busy()) { return true; }
        $this->fail(2014, 'HY000', self::OOS);
        return false;
    }

    /**
     * mysqlnd's reading of one reply: a server error is recorded; an OK sets the counters (empty info is NULL); a result
     * set header sets field_count, zeroes insert_id / info / warning_count and waits in $pend to be stored or used.
     */
    private function take(__McPdoMyResult $r): bool
    {
        if ($r->err !== null) {
            $this->chain = null;
            return $this->cmd($r->err);
        }
        $this->chain = $r->next;
        if (!$r->isSet) {
            $this->affectedRows = $r->affected;
            $this->insertId = $r->insertId;
            $this->info = $r->info === '' ? null : $r->info;
            $this->warningCount = $r->warnings;
            $this->fieldCount = 0;
            return true;
        }
        $this->fieldCount = \count($r->names);
        $this->insertId = 0;
        $this->info = null;
        $this->warningCount = 0;
        $this->pend = $r;
        return true;
    }

    /** COM_QUERY. $stream: leave the rows of a result set on the wire (real_query, MYSQLI_USE_RESULT). */
    public function send(string $sql, bool $stream): bool
    {
        $w = $this->liveWire();
        if (!$this->begin()) { return false; }
        try {
            $r = $w->query($sql, $stream);
        } catch (PDOException $e) {
            return $this->wireFail($e);
        }
        return $this->take($r);
    }

    /** store_result(): the waiting result with every row read; null when nothing waits or the read failed. */
    public function takeStored(): ?__McMysqliRs
    {
        $r = $this->pend;
        if ($r === null) { return null; }
        $this->pend = null;
        $this->clear();
        if ($r->stream && !$r->done) {
            $w = $this->wire;
            if ($w === null) { return null; }
            try {
                while (true) {
                    $row = $w->cursorRow($r);
                    if (\count($row) === 0) { break; }
                    $r->rows[] = $row;
                }
                if ($r->more) { $w->cursorNext($r); }
            } catch (PDOException $e) {
                $this->wireFail($e);
                return null;
            }
            if ($r->err !== null) {
                $this->cmd($r->err);
                return null;
            }
            $this->chain = $r->next;
        }
        $this->affectedRows = \count($r->rows);
        $this->warningCount = $r->warnings;
        return __McMysqliRs::make($this, $r, false);
    }

    /** use_result(): the waiting result, rows still to be read; the connection stays busy until the last one. */
    public function takeUsed(): ?__McMysqliRs
    {
        $r = $this->pend;
        if ($r === null) { return null; }
        $this->pend = null;
        $this->clear();
        $rs = __McMysqliRs::make($this, $r, true);
        $this->open = $rs;
        return $rs;
    }

    /** more_results(): the server promised another result behind the current one. */
    public function more(): bool
    {
        if ($this->chain !== null) { return true; }
        $s = $this->pend;
        if ($s === null && $this->open !== null) { $s = $this->open->r; }
        return $s !== null && $s->stream && !$s->done && ($s->status & 8) !== 0;
    }

    /** next_result(): what is unread of the current result is dropped, the next one becomes the current. */
    public function nextResult(): bool
    {
        $this->clear();
        if (!$this->more()) { return false; }
        if ($this->pend !== null) {
            $rs = $this->takeUsed();
            if ($rs !== null) { $rs->drain(); }
        }
        if ($this->open !== null) { $this->open->drain(); }
        $x = $this->chain;
        if ($x === null) { return false; }
        $this->affectedRows = -1;
        return $this->take($x);
    }

    /** mysqlnd copies a name into a C string: it ends at the first NUL. */
    public static function cutNul(string $s): string
    {
        $z = \strpos($s, "\0");
        return $z === false ? $s : \substr($s, 0, $z);
    }

    /** A transaction name as it goes into the comment after the verb: only A-Z a-z 0-9 space - _ = survive. */
    public static function txName(string $name): string
    {
        $name = self::cutNul($name);
        $o = '';
        $n = \strlen($name);
        for ($i = 0; $i < $n; $i++) {
            $c = \ord($name[$i]);
            if (($c >= 48 && $c <= 57) || ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || $c === 32 || $c === 45 || $c === 61 || $c === 95) { $o .= $name[$i]; }
        }
        return $o;
    }

    /**
     * get_warnings(): SHOW WARNINGS read as an unbuffered result to its end, as mysqli does (so the connection ends up with
     * affected_rows 0, the SHOW's field count and its warning count). The command's own failure is recorded, never thrown.
     */
    public function warnings(): false|mysqli_warning
    {
        $mode = self::$mode;
        self::$mode = 0;
        try {
            if (!$this->send('SHOW WARNINGS', true)) { return false; }
            $rs = $this->takeUsed();
            if ($rs === null) { return false; }
            /** @var array<int,string> */
            $msgs = [];
            /** @var array<int,int> */
            $nos = [];
            while (true) {
                $row = $rs->row();
                if ($row === null) { break; }
                $nos[] = (int) $row[1];
                $msgs[] = (string) $row[2];
            }
            $rs->free();
            return \count($msgs) === 0 ? false : mysqli_warning::__mcNew($msgs, $nos);
        } finally {
            self::$mode = $mode;
        }
    }

    /** @return ?string the packed table row, null for a name mysqlnd does not know */
    public static function charsetRow(string $name): ?string
    {
        $n = \strtolower($name);
        return isset(self::CHARSETS[$n]) ? self::CHARSETS[$n] : null;
    }

    public function charsetObject(): object
    {
        $p = \explode('|', $this->csRow);
        $o = new \stdClass();
        $o->charset = $this->charset;
        $o->collation = $p[1];
        $o->dir = '';
        $o->min_length = (int) $p[2];
        $o->max_length = (int) $p[3];
        $o->number = (int) $p[0];
        $o->state = 1;
        $o->comment = $p[4];
        return $o;
    }

    public function setCharset(string $cs): bool
    {
        $this->link();
        $cs = self::cutNul($cs);
        $row = self::charsetRow($cs);
        if ($row === null) { return $this->fail(2019, 'HY000', 'Invalid character set was provided'); }
        if (!$this->send('SET NAMES ' . $cs, false)) { return false; }
        $this->charset = \strtolower($cs);
        $this->csRow = $row;
        return true;
    }

    /**
     * mysqlnd_cset_escape_slashes / _quotes (fuzzed against php on both servers): a valid multibyte character is copied
     * whole, a lone lead byte of a multibyte charset is backslashed, the 7 specials are escaped. UTF-8 and the
     * single-byte charsets need no walk: no byte of a multibyte UTF-8 sequence is special. NO_BACKSLASH_ESCAPES (the
     * server status of the latest OK/EOF) doubles the quote and nothing else, which no charset's trail byte can be.
     */
    public static function escape(string $s, string $cs, bool $noBackslash): string
    {
        if ($noBackslash) { return \str_replace("'", "''", $s); }
        $plain = ["\0" => '\0', "\n" => '\n', "\r" => '\r', '\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\x1a" => '\Z'];
        if ($cs !== 'big5' && $cs !== 'gbk' && $cs !== 'sjis' && $cs !== 'cp932' && $cs !== 'euckr' && $cs !== 'gb2312'
            && $cs !== 'ujis' && $cs !== 'eucjpms' && $cs !== 'gb18030') {
            return \strtr($s, $plain);
        }
        $n = \strlen($s);
        $o = '';
        for ($i = 0; $i < $n; $i++) {
            $c = \ord($s[$i]);
            $c1 = $i + 1 < $n ? \ord($s[$i + 1]) : -1;
            $len = 0;
            $lead = false;
            if ($cs === 'big5') {
                $lead = $c >= 0xA1 && $c <= 0xF9;
                if ($lead && (($c1 >= 0x40 && $c1 <= 0x7E) || ($c1 >= 0xA1 && $c1 <= 0xFE))) { $len = 2; }
            } elseif ($cs === 'gbk') {
                $lead = $c >= 0x81 && $c <= 0xFE;
                if ($lead && (($c1 >= 0x40 && $c1 <= 0x7E) || ($c1 >= 0x80 && $c1 <= 0xFE))) { $len = 2; }
            } elseif ($cs === 'sjis' || $cs === 'cp932') {
                $lead = ($c >= 0x81 && $c <= 0x9F) || ($c >= 0xE0 && $c <= 0xFC);
                if ($lead && (($c1 >= 0x40 && $c1 <= 0x7E) || ($c1 >= 0x80 && $c1 <= 0xFC))) { $len = 2; }
            } elseif ($cs === 'euckr') {
                // mysqlnd's check_mb_euckr tests the second byte only
                $lead = $c >= 0xA1 && $c <= 0xFE;
                if ($c >= 0x80 && $c1 >= 0xA1 && $c1 <= 0xFE) { $len = 2; }
            } elseif ($cs === 'gb2312') {
                $lead = $c >= 0xA1 && $c <= 0xF7;
                if ($lead && $c1 >= 0xA1 && $c1 <= 0xFE) { $len = 2; }
            } elseif ($cs === 'ujis' || $cs === 'eucjpms') {
                $c2 = $i + 2 < $n ? \ord($s[$i + 2]) : -1;
                $lead = ($c >= 0xA1 && $c <= 0xFE) || $c === 0x8E || $c === 0x8F;
                if ($c >= 0xA1 && $c <= 0xFE && $c1 >= 0xA1 && $c1 <= 0xFE) { $len = 2; }
                elseif ($c === 0x8E && $c1 >= 0xA1 && $c1 <= 0xDF) { $len = 2; }
                elseif ($c === 0x8F && $c1 >= 0xA1 && $c1 <= 0xFE && $c2 >= 0xA1 && $c2 <= 0xFE) { $len = 3; }
            } else {
                // gb18030: mysqlnd's mbcharlen answers 0 for a lone lead byte, so it is never backslashed
                $c2 = $i + 2 < $n ? \ord($s[$i + 2]) : -1;
                $c3 = $i + 3 < $n ? \ord($s[$i + 3]) : -1;
                if ($c >= 0x81 && $c <= 0xFE) {
                    if (($c1 >= 0x40 && $c1 <= 0x7E) || ($c1 >= 0x80 && $c1 <= 0xFE)) { $len = 2; }
                    elseif ($c1 >= 0x30 && $c1 <= 0x39 && $c2 >= 0x81 && $c2 <= 0xFE && $c3 >= 0x30 && $c3 <= 0x39) { $len = 4; }
                }
            }
            if ($len > 0) {
                $o .= \substr($s, $i, $len);
                $i += $len - 1;
                continue;
            }
            $ch = $s[$i];
            $o .= $lead ? '\\' . $ch : (isset($plain[$ch]) ? $plain[$ch] : $ch);
        }
        return $o;
    }

    public function escapeString(string $s): string
    {
        $w = $this->liveWire();
        return self::escape($s, $this->charset, ($w->status & 512) !== 0);
    }

    /** mysqli_options(): true for an option mysqlnd knows (stored, applied by the next connect), false otherwise. */
    public function option(int $option, mixed $value): bool
    {
        $this->alive();
        if ($option === 0) { $this->connectTimeout = (int) $value; return true; }
        if ($option === 11) { $this->readTimeout = (int) $value; return true; }
        if ($option === 3) { $this->initCmds[] = (string) $value; return true; }
        if ($option === 7) {
            $cs = (string) $value;
            if (self::charsetRow($cs) === null) { return $this->fail(2019, 'HY000', 'Unknown character set'); }
            $this->optCharset = $cs;
            return true;
        }
        if ($option === 201) { $this->intAndFloatNative = (bool) $value; return true; }
        // MYSQLND_NET_CMD_BUFFER_MIN_SIZE
        if ($option === 202) { return (int) $value >= 4096; }
        // compress, named pipe, read_default_file/group, local_infile, protocol, ssl_verify_server_cert, server_public_key,
        // can_handle_expired_passwords, load_data_local_dir, net_read_buffer_size: accepted, nothing here acts on them
        return $option === 1 || $option === 2 || $option === 4 || $option === 5 || $option === 8 || $option === 9
            || $option === 21 || $option === 35 || $option === 37 || $option === 43 || $option === 203;
    }

    public function sslSet(?string $key, ?string $cert, ?string $ca, ?string $capath, ?string $cipher): void
    {
        $this->alive();
        $this->sslKey = $key === null ? '' : $key;
        $this->sslCert = $cert === null ? '' : $cert;
        $this->sslCa = $ca === null ? '' : $ca;
        $this->sslCaPath = $capath === null ? '' : $capath;
        $this->sslCipher = $cipher;
        $this->sslWanted = $key !== null || $cert !== null || $ca !== null || $capath !== null || $cipher !== null;
    }

    private static function ini(string $k): string
    {
        $v = \ini_get($k);
        return \is_string($v) ? $v : '';
    }

    /**
     * mysqli_common_connect + mysqlnd connect. A null argument falls back to its mysqli.default_* ini value; host
     * `localhost` (or none) is the unix socket: the $socket argument, else mysqli.default_socket, else the first of the
     * usual paths that exists, else TCP to 127.0.0.1 (Zend would fail with "No such file or directory" there).
     * A failure is a connect error: recorded process-wide, thrown iff the report mode has STRICT (ERROR not needed),
     * and the handle is left INIT ($real: real_connect) or CLOSED (the constructor, connect()).
     */
    public function connect(?string $host, ?string $user, ?string $pw, ?string $db, ?int $port, ?string $socket, int $flags, bool $real): bool
    {
        $this->alive();
        $this->dropResults();
        if ($this->wire !== null) {
            $this->wire->close();
            $this->wire = null;
        }
        $this->st = self::INIT;
        $host = $host === null || $host === '' ? self::ini('mysqli.default_host') : $host;
        if (\strlen($host) > 2 && \strncasecmp($host, 'p:', 2) === 0) { $host = \substr($host, 2); }
        if ($host === '') { $host = 'localhost'; }
        $user = $user === null ? self::ini('mysqli.default_user') : $user;
        $pw = $pw === null ? self::ini('mysqli.default_pw') : $pw;
        $db = $db === null ? '' : $db;
        if ($port === null || $port === 0) {
            $dp = self::ini('mysqli.default_port');
            $port = $dp === '' ? 3306 : (int) $dp;
        }
        $timeout = (float) $this->connectTimeout;
        if ($timeout <= 0.0) {
            $t = self::ini('default_socket_timeout');
            $timeout = $t === '' ? 60.0 : (float) $t;
        }
        $wire = null;
        try {
            $path = '';
            if (\strtolower($host) === 'localhost') {
                if ($socket !== null && $socket !== '') {
                    $path = $socket;
                } else {
                    $cands = [self::ini('mysqli.default_socket'), '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock', '/var/lib/mysql/mysql.sock'];
                    foreach ($cands as $c) {
                        if ($c !== '' && \file_exists($c)) { $path = $c; break; }
                    }
                }
            }
            if ($path !== '') {
                $wire = __McPdoMyWire::connectUnix($path, $timeout);
                $this->hostInfo = 'Localhost via UNIX socket';
            } else {
                $tcp = \strtolower($host) === 'localhost' ? '127.0.0.1' : $host;
                $wire = new __McPdoMyWire($tcp, $port, $timeout);
                $this->hostInfo = $tcp . ' via TCP/IP';
            }
            $wire->handshake();
            $cs = $this->optCharset === '' ? 'utf8mb4' : \strtolower($this->optCharset);
            $row = self::charsetRow($cs);
            if ($row === null) { $cs = 'utf8mb4'; $row = (string) self::charsetRow($cs); }
            if ($cs === 'utf8mb4' && __McPdoMyWire::charsetNo('utf8mb4', $wire->serverVersion) === 255) { $row = self::UTF8MB4_0900; }
            $tls = null;
            if (($flags & 2048) !== 0 || $this->sslWanted) {
                $tls = new __McPdoTlsOpts();
                $tls->host = $host;
                $verify = ($flags & 1073741824) !== 0 || (($this->sslCa !== '' || $this->sslCaPath !== '') && ($flags & 64) === 0);
                $tls->verifyChain = $verify;
                $tls->verifyName = $verify;
                if ($verify) {
                    $tls->caFile = $this->sslCa;
                    $tls->caDir = $this->sslCaPath;
                    $tls->systemRoots = $this->sslCa === '' && $this->sslCaPath === '';
                }
                if ($this->sslCipher !== null) { $tls->ciphers = $this->sslCipher === '' ? "\x01" : $this->sslCipher; }
                if ($this->sslCert !== '') {
                    $tls->cert = $this->sslCert;
                    $tls->key = $this->sslKey;
                }
            }
            // CLIENT_MULTI_STATEMENTS is stripped as mysqli does (multi_query turns it on per call); compress and
            // interactive are accepted and not acted on
            $wire->auth($user, $pw, $db, (int) $row, ($flags & 2) !== 0, false, ($flags & 256) !== 0, $tls);
            foreach ($this->initCmds as $ic) {
                for ($r = $wire->query($ic); $r !== null; $r = $r->next) {
                    if ($r->err !== null) { throw __McPdo::connectFail($r->err->state, $r->err->no, $r->err->msg); }
                }
            }
            if ($this->readTimeout > 0) {
                $wire->setTimeout((float) $this->readTimeout);
                $wire->explicitTimeout = true;
            }
        } catch (PDOException $e) {
            if ($wire !== null) { $wire->close(); }
            self::$connErrno = $e->driverCode;
            self::$connError = $e->driverMsg;
            // mysqlnd words an authentication refusal HY000 whatever the server sent; an INIT_COMMAND keeps its own state
            $state = $e->sqlstate === '' ? 'HY000' : $e->sqlstate;
            $this->errno = $e->driverCode;
            $this->error = $e->driverMsg;
            $this->sqlstate = $state;
            $this->st = $real ? self::INIT : self::CLOSED;
            if ((self::$mode & 2) !== 0) { throw mysqli_sql_exception::__mcNew($e->driverMsg, $e->driverCode, $state); }
            return false;
        }
        $this->wire = $wire;
        $this->st = self::VALID;
        $this->charset = $cs;
        $this->csRow = $row;
        $sv = $wire->serverVersion;
        // MariaDB announces itself as 5.5.5-<version> for old clients; mysqlnd strips the prefix
        if (\strncmp($sv, '5.5.5-', 6) === 0) { $sv = \substr($sv, 6); }
        $this->serverInfo = $sv;
        $v = \explode('.', $sv);
        $this->serverVersion = (int) $v[0] * 10000 + (isset($v[1]) ? (int) $v[1] : 0) * 100 + (isset($v[2]) ? (int) $v[2] : 0);
        $this->threadId = $wire->connId;
        $this->clear();
        $this->affectedRows = 0;
        $this->insertId = 0;
        $this->info = $wire->okInfo === '' ? null : $wire->okInfo;
        $this->warningCount = 0;
        $this->fieldCount = 0;
        self::$connErrno = 0;
        self::$connError = null;
        return true;
    }

    public function close(): void
    {
        $this->dropResults();
        if ($this->wire !== null) {
            $this->wire->close();
            $this->wire = null;
        }
        $this->st = self::CLOSED;
    }
}

class mysqli
{
    public int|string $affected_rows { get => __McMysqliConn::of($this)->connected()->affectedRows; set { __McMysqliConn::ro($this, 'affected_rows'); } }
    public string $client_info { get => __McMysqliConn::clientInfo(); set { __McMysqliConn::ro($this, 'client_info'); } }
    public int $client_version { get => \PHP_VERSION_ID; set { __McMysqliConn::ro($this, 'client_version'); } }
    public int $connect_errno { get => __McMysqliConn::$connErrno; set { __McMysqliConn::ro($this, 'connect_errno'); } }
    public ?string $connect_error { get => __McMysqliConn::$connError; set { __McMysqliConn::ro($this, 'connect_error'); } }
    public int $errno { get => __McMysqliConn::of($this)->alive()->errno; set { __McMysqliConn::ro($this, 'errno'); } }
    public string $error { get => __McMysqliConn::of($this)->alive()->error; set { __McMysqliConn::ro($this, 'error'); } }
    public array $error_list { get => __McMysqliConn::of($this)->connected()->errorList; set { __McMysqliConn::ro($this, 'error_list'); } }
    public int $field_count { get => __McMysqliConn::of($this)->prop()->fieldCount; set { __McMysqliConn::ro($this, 'field_count'); } }
    public string $host_info { get => __McMysqliConn::of($this)->prop()->hostInfo; set { __McMysqliConn::ro($this, 'host_info'); } }
    public ?string $info { get => __McMysqliConn::of($this)->prop()->info; set { __McMysqliConn::ro($this, 'info'); } }
    public int|string $insert_id { get => __McMysqliConn::of($this)->prop()->insertId; set { __McMysqliConn::ro($this, 'insert_id'); } }
    public int $protocol_version { get => __McMysqliConn::of($this)->prop()->protocolVersion; set { __McMysqliConn::ro($this, 'protocol_version'); } }
    public string $server_info { get => __McMysqliConn::of($this)->prop()->serverInfo; set { __McMysqliConn::ro($this, 'server_info'); } }
    public int $server_version { get => __McMysqliConn::of($this)->prop()->serverVersion; set { __McMysqliConn::ro($this, 'server_version'); } }
    public string $sqlstate { get => __McMysqliConn::of($this)->prop()->sqlstate; set { __McMysqliConn::ro($this, 'sqlstate'); } }
    public int $thread_id { get => __McMysqliConn::of($this)->prop()->threadId; set { __McMysqliConn::ro($this, 'thread_id'); } }
    public int $warning_count { get => __McMysqliConn::of($this)->prop()->warningCount; set { __McMysqliConn::ro($this, 'warning_count'); } }

    /** Internal: the connection state (see __McMysqliConn). Not a Zend property; the surface audit skips `__mc*`. Private, so
     * get_object_vars / foreach / json_encode from outside do not list it (Zend's mysqli shows them nothing). */
    private ?__McMysqliConn $__mc = null;

    /** Internal: the state, created on first use (a subclass constructor need not call parent::__construct). */
    public function __mcConn(): __McMysqliConn
    {
        if ($this->__mc === null) { $this->__mc = new __McMysqliConn(); }
        return $this->__mc;
    }

    /**
     * var_dump/print_r: Zend's get_debug_info lists the properties whose read does not throw, in registration order.
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        $o = [];
        foreach (__McMysqliConn::PROPS as $p) {
            try {
                $o[$p] = $this->$p;
            } catch (Error $e) {
            }
        }
        return $o;
    }

    public function __construct(?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null)
    {
        $c = __McMysqliConn::of($this);
        // no argument at all is mysqli_init(); an explicit null still connects with the defaults
        if (\func_num_args() === 0) { return; }
        $c->connect($hostname, $username, $password, $database, $port, $socket, 0, false);
    }

    public function autocommit(bool $enable): bool
    {
        return __McMysqliConn::of($this)->link()->send($enable ? 'SET AUTOCOMMIT=1' : 'SET AUTOCOMMIT=0', false);
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        return $this->__mcBeginTx($flags, $name, 'mysqli::begin_transaction', 1);
    }

    /** begin_transaction() and mysqli_begin_transaction(): $fn and $arg (the flags' argument position) word the argument errors. */
    public function __mcBeginTx(int $flags, ?string $name, string $fn, int $arg): bool
    {
        $c = __McMysqliConn::of($this)->link();
        if ($flags < 0) { throw new ValueError($fn . '(): Argument #' . $arg . ' ($flags) must be one of the MYSQLI_TRANS_* constants'); }
        if ($name === '') { throw new ValueError($fn . '(): Argument #' . ($arg + 1) . ' ($name) must not be empty'); }
        $sql = 'START TRANSACTION';
        if ($name !== null) { $sql .= ' /*' . __McMysqliConn::txName($name) . '*/'; }
        $opt = '';
        if (($flags & 1) !== 0) { $opt = 'WITH CONSISTENT SNAPSHOT'; }
        if (($flags & 2) !== 0) { $opt .= ($opt === '' ? '' : ', ') . 'READ WRITE'; }
        elseif (($flags & 4) !== 0) { $opt .= ($opt === '' ? '' : ', ') . 'READ ONLY'; }
        if ($opt !== '') { $sql .= ' ' . $opt; }
        return $c->send($sql, false);
    }

    /** commit() and rollback(): chain and release follow mysqlnd (both bits of a pair cancel out). */
    public function __mcEndTx(string $verb, int $flags, ?string $name): bool
    {
        $c = __McMysqliConn::of($this)->link();
        $sql = $verb;
        if ($name !== null) { $sql .= ' /*' . __McMysqliConn::txName($name) . '*/'; }
        if (($flags & 1) !== 0 && ($flags & 2) === 0) { $sql .= ' AND CHAIN'; }
        elseif (($flags & 2) !== 0 && ($flags & 1) === 0) { $sql .= ' AND NO CHAIN'; }
        if (($flags & 4) !== 0 && ($flags & 8) === 0) { $sql .= ' RELEASE'; }
        elseif (($flags & 8) !== 0 && ($flags & 4) === 0) { $sql .= ' NO RELEASE'; }
        return $c->send($sql, false);
    }

    /** savepoint() and release_savepoint(). */
    public function __mcSavepoint(string $verb, string $name, string $fn, int $arg): bool
    {
        $c = __McMysqliConn::of($this)->link();
        if ($name === '') { throw new ValueError($fn . '(): Argument #' . $arg . ' ($name) must not be empty'); }
        return $c->send($verb . ' `' . __McMysqliConn::cutNul($name) . '`', false);
    }

    public function change_user(string $username, #[\SensitiveParameter] string $password, ?string $database): bool
    {
        $c = __McMysqliConn::of($this);
        $w = $c->liveWire();
        if (!$c->begin()) { return false; }
        // a success is an OK that leaves field_count alone; user and database are C strings (they end at a NUL)
        $db = $database === null ? '' : __McMysqliConn::cutNul($database);
        try {
            $e = $w->changeUser(__McMysqliConn::cutNul($username), $password, $db, (int) \explode('|', $c->csRow)[0]);
        } catch (PDOException $x) {
            return $c->wireFail($x);
        }
        if ($e !== null) { return $c->cmd($e); }
        $c->affectedRows = 0;
        $c->insertId = 0;
        $c->info = null;
        $c->warningCount = 0;
        return true;
    }

    public function character_set_name(): string
    {
        return __McMysqliConn::of($this)->link()->charset;
    }

    public function close(): true
    {
        __McMysqliConn::of($this)->alive()->close();
        return true;
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        return $this->__mcEndTx('COMMIT', $flags, $name);
    }

    public function connect(?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null): bool
    {
        return __McMysqliConn::of($this)->connect($hostname, $username, $password, $database, $port, $socket, 0, false);
    }

    public function debug(string $options): true
    {
        throw new Error('mysqli: not implemented');
    }

    public function dump_debug_info(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function escape_string(string $string): string
    {
        return __McMysqliConn::of($this)->escapeString($string);
    }

    public function execute_query(string $query, ?array $params = null): bool|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_charset(): ?object
    {
        return __McMysqliConn::of($this)->link()->charsetObject();
    }

    public function get_client_info(): string
    {
        return __McMysqliConn::clientInfo();
    }

    public function get_connection_stats(): array
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_server_info(): string
    {
        return __McMysqliConn::of($this)->link()->serverInfo;
    }

    public function get_warnings(): false|mysqli_warning
    {
        $c = __McMysqliConn::of($this)->link();
        return $c->warningCount === 0 ? false : $c->warnings();
    }

    public function init()
    {
        if ($this->__mcConn()->st === __McMysqliConn::CLOSED) { $this->__mc = new __McMysqliConn(); }
        return null;
    }

    public function kill(int $process_id): bool
    {
        return $this->__mcKill($process_id, 'mysqli::kill', 1);
    }

    /** kill() and mysqli_kill(). Killing the connection's own thread just closes it, as mysqlnd does. */
    public function __mcKill(int $id, string $fn, int $arg): bool
    {
        if ($id <= 0) { throw new ValueError($fn . '(): Argument #' . $arg . ' ($process_id) must be greater than 0'); }
        $c = __McMysqliConn::of($this);
        $w = $c->liveWire();
        if (!$c->begin()) { return false; }
        if ($id === $c->threadId) {
            $w->close();
            return true;
        }
        try {
            $e = $w->processKill($id);
        } catch (PDOException $x) {
            return $c->wireFail($x);
        }
        if ($e === null) { $c->info = null; }
        return $c->cmd($e);
    }

    public function more_results(): bool
    {
        return __McMysqliConn::of($this)->link()->more();
    }

    public function multi_query(string $query): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function next_result(): bool
    {
        return __McMysqliConn::of($this)->link()->nextResult();
    }

    public function options(int $option, $value): bool
    {
        return __McMysqliConn::of($this)->option($option, $value);
    }

    public function ping(): bool
    {
        $c = __McMysqliConn::of($this);
        $w = $c->liveWire();
        if (!$c->begin()) { return false; }
        if (!$w->ping()) { return $c->fail(2006, 'HY000', 'MySQL server has gone away'); }
        $c->info = null;
        return true;
    }

    public static function poll(?array &$read, ?array &$error, array &$reject, int $seconds, int $microseconds = 0): false|int
    {
        throw new Error('mysqli: not implemented');
    }

    public function prepare(string $query): false|mysqli_stmt
    {
        throw new Error('mysqli: not implemented');
    }

    public function query(string $query, int $result_mode = 0): bool|mysqli_result
    {
        return $this->__mcQuery($query, $result_mode, 'mysqli::query', 1);
    }

    /** query() and mysqli_query(): $fn and $arg (the query's argument position) word the argument errors. */
    public function __mcQuery(string $query, int $mode, string $fn, int $arg): bool|mysqli_result
    {
        if ($query === '') { throw new ValueError($fn . '(): Argument #' . $arg . ' ($query) must not be empty'); }
        $m = $mode & ~8;
        if ($m !== 0 && $m !== 1) {
            throw new ValueError($fn . '(): Argument #' . ($arg + 1) . ' ($result_mode) must be either MYSQLI_USE_RESULT or MYSQLI_STORE_RESULT with MYSQLI_ASYNC as an optional bitmask flag');
        }
        if (($mode & 8) !== 0) { throw new Error('mysqli: not implemented'); }
        $c = __McMysqliConn::of($this);
        if (!$c->send($query, $m === 1)) { return false; }
        if ($c->pend === null) { return true; }
        return new mysqli_result($this, $m);
    }

    public function real_connect(?string $hostname = null, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?string $database = null, ?int $port = null, ?string $socket = null, int $flags = 0): bool
    {
        return __McMysqliConn::of($this)->connect($hostname, $username, $password, $database, $port, $socket, $flags, true);
    }

    public function real_escape_string(string $string): string
    {
        return __McMysqliConn::of($this)->escapeString($string);
    }

    public function real_query(string $query): bool
    {
        return __McMysqliConn::of($this)->send($query, true);
    }

    public function reap_async_query(): bool|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function refresh(int $flags): bool
    {
        $c = __McMysqliConn::of($this);
        // mysqli never reports a failed refresh: it is recorded, not thrown (an unconnected handle answers out of sync)
        $mode = __McMysqliConn::$mode;
        __McMysqliConn::$mode = 0;
        try {
            if ($c->st === __McMysqliConn::INIT) { return $c->fail(2014, 'HY000', __McMysqliConn::OOS); }
            $w = $c->liveWire();
            if (!$c->begin()) { return false; }
            try {
                $e = $w->refresh($flags);
            } catch (PDOException $x) {
                return $c->wireFail($x);
            }
            if ($e === null) { $c->info = null; }
            return $c->cmd($e);
        } finally {
            __McMysqliConn::$mode = $mode;
        }
    }

    public function release_savepoint(string $name): bool
    {
        return $this->__mcSavepoint('RELEASE SAVEPOINT', $name, 'mysqli::release_savepoint', 1);
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        return $this->__mcEndTx('ROLLBACK', $flags, $name);
    }

    public function savepoint(string $name): bool
    {
        return $this->__mcSavepoint('SAVEPOINT', $name, 'mysqli::savepoint', 1);
    }

    public function select_db(string $database): bool
    {
        $c = __McMysqliConn::of($this);
        $w = $c->liveWire();
        if (!$c->begin()) { return false; }
        try {
            $e = $w->initDb($database);
        } catch (PDOException $x) {
            return $c->wireFail($x);
        }
        if ($e === null) { $c->info = null; }
        return $c->cmd($e);
    }

    public function set_charset(string $charset): bool
    {
        return __McMysqliConn::of($this)->setCharset($charset);
    }

    public function set_opt(int $option, $value): bool
    {
        return __McMysqliConn::of($this)->option($option, $value);
    }

    public function ssl_set(?string $key, ?string $certificate, ?string $ca_certificate, ?string $ca_path, ?string $cipher_algos): true
    {
        __McMysqliConn::of($this)->sslSet($key, $certificate, $ca_certificate, $ca_path, $cipher_algos);
        return true;
    }

    public function stat(): false|string
    {
        $c = __McMysqliConn::of($this);
        $w = $c->liveWire();
        // mysqli never reports a failed stat: it is recorded, not thrown
        $mode = __McMysqliConn::$mode;
        __McMysqliConn::$mode = 0;
        try {
            if (!$c->begin()) { return false; }
            try {
                return $w->statistics();
            } catch (PDOException $x) {
                return $c->wireFail($x);
            }
        } finally {
            __McMysqliConn::$mode = $mode;
        }
    }

    public function stmt_init(): false|mysqli_stmt
    {
        throw new Error('mysqli: not implemented');
    }

    public function store_result(int $mode = 0): false|mysqli_result
    {
        return __McMysqliConn::of($this)->link()->pend === null ? false : new mysqli_result($this, 0);
    }

    public function thread_safe(): bool
    {
        return true;
    }

    public function use_result(): false|mysqli_result
    {
        return __McMysqliConn::of($this)->link()->pend === null ? false : new mysqli_result($this, 1);
    }
}

final class mysqli_driver
{
    public string $client_info { get => __McMysqliConn::clientInfo(); set { __McMysqliConn::ro($this, 'client_info'); } }
    public int $client_version { get => \PHP_VERSION_ID; set { __McMysqliConn::ro($this, 'client_version'); } }
    public int $driver_version { get => 101009; set { __McMysqliConn::ro($this, 'driver_version'); } }
    public int $report_mode { get => __McMysqliConn::$mode; set { __McMysqliConn::$mode = $value; } }

    /** @return array<string,mixed> Zend's dump leaves out the deprecated driver_version */
    public function __debugInfo(): array
    {
        return ['client_info' => $this->client_info, 'client_version' => $this->client_version, 'report_mode' => $this->report_mode];
    }
}

/**
 * Zend writes the columns of a fetch_object row regardless of visibility: the writes run in the object's own class scope.
 * A FUNCTION, not a method: a closure made inside a method keeps that method's $this when bound to another object
 * (tests/aot/repro/wrong/closure_bind_in_method_keeps_this.php).
 */
function __mc_mysqli_hydrate(object $o, __McPdoMyResult $r, array $row): void
{
    $set = \Closure::bind(function (__McPdoMyResult $r, array $row): void {
        $n = \count($r->names);
        for ($i = 0; $i < $n; $i++) {
            $k = $r->names[$i];
            $this->$k = $row[$i];
        }
    }, $o, \get_class($o));
    $set($r, $row);
}

/**
 * The state behind one mysqli_result: the wire result, the read position and the open-ness. Buffered (store_result):
 * every row is in $r->rows and data_seek() moves $pos. Unbuffered (use_result): rows are read off the wire one at a
 * time (or, for a result the chain already buffered, off $r->rows) and the connection stays busy until the last one.
 */
final class __McMysqliRs
{
    public ?__McMysqliConn $c = null;
    public ?__McPdoMyResult $r = null;
    public bool $unbuf = false;
    /** The rows are in $r->rows (not on the wire). */
    public bool $mem = true;
    public int $pos = 0;
    /** Rows handed out so far. */
    public int $seen = 0;
    /** @var ?array<int,?string> the row fetched last */
    public ?array $cur = null;
    public bool $ended = false;
    public int $field = 0;
    /** numRows()'s answer, held in a union slot so it crosses the property hook tagged. */
    public int|string $rows = 0;
    /** @var array<int,int> per column: 0 string as sent, 1 integer, 2 float, 3 BIT (see typed()) */
    public array $kinds = [];
    /** MYSQLI_OPT_INT_AND_FLOAT_NATIVE as the connection had it at the last row fetch (mysqlnd reads it per fetch). */
    public bool $native = false;
    /** Some column is not handed out as the string the wire carries. */
    public bool $conv = false;
    public bool $bit = false;

    public static function make(__McMysqliConn $c, __McPdoMyResult $r, bool $unbuf): self
    {
        $rs = new self();
        $rs->c = $c;
        $rs->r = $r;
        $rs->unbuf = $unbuf;
        $rs->mem = !$r->stream || $r->done;
        $native = $c->intAndFloatNative;
        $rs->native = $native;
        $n = \count($r->types);
        for ($i = 0; $i < $n; $i++) {
            $t = $r->types[$i];
            $k = 0;
            if ($t === 16) { $k = 3; }
            elseif (($r->flags[$i] & 64) === 0) {
                if ($t === 1 || $t === 2 || $t === 3 || $t === 8 || $t === 9) { $k = 1; }
                elseif ($t === 4 || $t === 5) { $k = 2; }
            }
            $rs->kinds[] = $k;
            if ($k !== 0) { $rs->conv = true; }
            if ($k === 3) { $rs->bit = true; }
        }
        return $rs;
    }

    /** The live state behind a result; a freed (or never filled) result is an Error. */
    public static function of(mysqli_result $o): self
    {
        $rs = $o->__mcRs();
        if ($rs->r === null) { throw new Error('mysqli_result object is already closed'); }
        return $rs;
    }

    /** lengths / type read a freed result as "not initialised yet". */
    public static function ofProp(mysqli_result $o): self
    {
        $rs = $o->__mcRs();
        if ($rs->r === null) { throw new Error('Property access is not allowed yet'); }
        return $rs;
    }

    public function fields(): int
    {
        $r = $this->r;
        return $r === null ? 0 : \count($r->names);
    }

    /** The column definition as mysqli's field object (Zend's property order). */
    public function fieldObject(int $i): object
    {
        $r = $this->r;
        $o = new \stdClass();
        if ($r === null) { return $o; }
        $o->name = $r->names[$i];
        $o->orgname = $r->orgNames[$i];
        $o->table = $r->tables[$i];
        $o->orgtable = $r->orgTables[$i];
        $o->def = '';
        $o->db = $r->dbs[$i];
        $o->catalog = $r->cats[$i];
        $o->max_length = 0;
        $o->length = $r->lens[$i];
        $o->charsetnr = $r->charsets[$i];
        $t = $r->types[$i];
        // mysqlnd adds NUM_FLAG to the numeric types (not DECIMAL, not TIMESTAMP)
        $o->flags = $r->flags[$i] | (($t >= 1 && $t <= 9 && $t !== 7) || $t === 13 ? 32768 : 0);
        $o->type = $t;
        $o->decimals = $r->decs[$i];
        return $o;
    }

    /** fetch_field(): the field under the cursor, then the cursor moves; false past the last one. */
    public function nextField(): false|object
    {
        if ($this->field >= $this->fields()) { return false; }
        $o = $this->fieldObject($this->field);
        $this->field++;
        return $o;
    }

    public function numRows(): int|string
    {
        $r = $this->r;
        $n = 0;
        if ($r !== null) { $n = $this->unbuf ? ($this->ended ? $this->seen : 0) : \count($r->rows); }
        $this->rows = $n;
        return $this->rows;
    }

    /** @return ?array<int,int> byte lengths of the row fetched last; null before the first row, after the end of a buffered result and after a seek */
    public function lengths(): ?array
    {
        $row = $this->cur;
        if ($row === null) { return null; }
        $o = [];
        foreach ($row as $v) { $o[] = \is_string($v) ? \strlen($v) : 0; }
        return $o;
    }

    /**
     * The values of a row as mysqlnd hands them out: BIT as the number it holds (a string past the integer range), and with
     * MYSQLI_OPT_INT_AND_FLOAT_NATIVE integers and floats (a value outside the integer range stays the string the server sent;
     * ZEROFILL, DECIMAL, YEAR and the date types stay strings).
     *
     * @param array<int,?string> $row
     * @return array<int,mixed>
     */
    public function typed(array $row): array
    {
        /** @var array<int,mixed> */
        $o = [];
        foreach ($row as $i => $v) {
            $k = $this->kinds[$i];
            if ($v === null || $k === 0 || ($k !== 3 && !$this->native)) { $o[] = $v; }
            elseif ($k === 1) {
                $n = (int) $v;
                if ((string) $n === $v) { $o[] = $n; } else { $o[] = $v; }
            }
            elseif ($k === 2) { $o[] = (float) $v; }
            else {
                $n = 0;
                $l = \strlen($v);
                for ($j = 0; $j < $l; $j++) { $n = ($n << 8) | \ord($v[$j]); }
                if ($n >= 0) { if ($this->native) { $o[] = $n; } else { $o[] = (string) $n; } }
                else { $o[] = \sprintf('%u', $n); }
            }
        }
        return $o;
    }

    /** @return ?array<int,mixed> the next row; null at the end (the connection is released when an unbuffered result ends). Any fetch forgets the connection's last error, as mysqlnd's does. */
    public function row(): ?array
    {
        $r = $this->r;
        if ($r === null) { return null; }
        if ($this->c !== null) { $this->c->clear(); $this->native = $this->c->intAndFloatNative; }
        if ($this->mem) {
            if ($this->pos >= \count($r->rows)) {
                if ($this->unbuf) { $this->end(); } else { $this->cur = null; }
                return null;
            }
            $row = $r->rows[$this->pos];
            $this->pos++;
            $this->seen++;
            if ($this->conv && ($this->bit || $this->native)) { $tr = $this->typed($row); $this->cur = $tr; return $tr; }
            $this->cur = $row;
            return $row;
        }
        if ($this->ended) { return null; }
        $c = $this->c;
        if ($c === null) { return null; }
        $w = $c->wire;
        if ($w === null) {
            $this->end();
            return null;
        }
        try {
            $row = $w->cursorRow($r);
        } catch (PDOException $e) {
            $this->end();
            $c->wireFail($e);
            return null;
        }
        if (\count($row) === 0) {
            $this->end();
            return null;
        }
        $this->seen++;
        if ($this->conv && ($this->bit || $this->native)) { $tr = $this->typed($row); $this->cur = $tr; return $tr; }
        $this->cur = $row;
        return $row;
    }

    /** The last row of an unbuffered result was read (or the result is dropped): the connection is free again. */
    public function end(): void
    {
        if ($this->ended) { return; }
        $this->ended = true;
        $r = $this->r;
        $c = $this->c;
        if ($r === null || $c === null) { return; }
        if ($c->open === $this) { $c->open = null; }
        $c->affectedRows = 0;
        $c->warningCount = $r->warnings;
        if (!$this->mem) {
            $w = $c->wire;
            try {
                if ($r->more && $w !== null) { $w->cursorNext($r); }
            } catch (PDOException $e) {
                $c->wireFail($e);
                return;
            }
            if ($r->err !== null) {
                $c->cmd($r->err);
                return;
            }
            $c->chain = $r->next;
        }
    }

    /** Reads (and drops) what is left of an unbuffered result. */
    public function drain(): void
    {
        if ($this->ended) { return; }
        $r = $this->r;
        $c = $this->c;
        if ($r !== null && !$this->mem && $c !== null) {
            $w = $c->wire;
            if ($w !== null) {
                try {
                    while (\count($w->cursorRow($r, true)) > 0) {
                    }
                } catch (PDOException $e) {
                }
            }
        }
        $this->end();
    }

    public function free(): void
    {
        if ($this->r === null) { return; }
        if ($this->unbuf) { $this->drain(); }
        $this->r = null;
        $this->cur = null;
        $this->c = null;
    }

    /** @return array<string,mixed> the row under its column names; a repeated name keeps the last value at the first place */
    public function assoc(array $row): array
    {
        $r = $this->r;
        /** @var array<string,mixed> */
        $a = [];
        if ($r === null) { return $a; }
        $n = \count($r->names);
        for ($i = 0; $i < $n; $i++) { $a[$r->names[$i]] = $row[$i]; }
        return $a;
    }

    /**
     * NUM and ASSOC under one array: the index, then the name, per column. Its own method with an annotated `array`
     * return: a mixed-key array types UNKNOWN, and one built inline among arms of other shapes crosses the erased
     * channel with a raw buffer pointer (see PDOStatement's rowBoth).
     *
     * @return array<string,mixed>
     */
    public function both(array $row): array
    {
        $r = $this->r;
        /** @var array<string,mixed> */
        $a = [];
        if ($r === null) { return $a; }
        $n = \count($r->names);
        for ($i = 0; $i < $n; $i++) {
            $v = $row[$i];
            $a[$i] = $v;
            $a[$r->names[$i]] = $v;
        }
        return $a;
    }

    public static function modeError(string $fn, int $arg): ValueError
    {
        return new ValueError($fn . '(): Argument #' . $arg . ' ($mode) must be one of MYSQLI_NUM, MYSQLI_ASSOC, or MYSQLI_BOTH');
    }
}

/** foreach over a mysqli_result: the rows as associative arrays under keys 0, 1, 2...; an unbuffered result can be iterated once. */
final class __McMysqliIter implements Iterator
{
    private mysqli_result $res;
    /** @var ?array<string,?string> */
    private ?array $row = null;
    private int $i = 0;

    public function __construct(mysqli_result $res)
    {
        $this->res = $res;
    }

    private function fetch(__McMysqliRs $rs): void
    {
        $row = $rs->row();
        $this->row = $row === null ? null : $rs->assoc($row);
    }

    public function rewind(): void
    {
        $rs = __McMysqliRs::of($this->res);
        $this->i = 0;
        if ($rs->unbuf) {
            if ($rs->seen > 0) {
                $this->row = null;
                return;
            }
        } else {
            $rs->pos = 0;
            $rs->cur = null;
        }
        $this->fetch($rs);
    }

    public function valid(): bool
    {
        return $this->row !== null;
    }

    public function current(): mixed
    {
        return $this->row;
    }

    public function key(): mixed
    {
        return $this->i;
    }

    public function next(): void
    {
        $this->i = $this->i + 1;
        $this->fetch(__McMysqliRs::of($this->res));
    }
}

class mysqli_result implements IteratorAggregate
{
    public int $current_field { get => __McMysqliRs::of($this)->field; set { __McMysqliConn::ro($this, 'current_field'); } }
    public int $field_count { get => __McMysqliRs::of($this)->fields(); set { __McMysqliConn::ro($this, 'field_count'); } }
    public ?array $lengths { get => __McMysqliRs::ofProp($this)->lengths(); set { __McMysqliConn::ro($this, 'lengths'); } }
    public int|string $num_rows { get => __McMysqliRs::of($this)->numRows(); set { __McMysqliConn::ro($this, 'num_rows'); } }
    public int $type { get => __McMysqliRs::ofProp($this)->unbuf ? 1 : 0; set { __McMysqliConn::ro($this, 'type'); } }

    /** Internal: see mysqli::$__mc. */
    private ?__McMysqliRs $__mc = null;

    public function __mcRs(): __McMysqliRs
    {
        if ($this->__mc === null) { $this->__mc = new __McMysqliRs(); }
        return $this->__mc;
    }

    /** @return array<string,mixed> the properties whose read does not throw, in registration order */
    public function __debugInfo(): array
    {
        /** @var array<string,mixed> */
        $o = [];
        try { $o['current_field'] = $this->current_field; } catch (Error $e) { }
        try { $o['field_count'] = $this->field_count; } catch (Error $e) { }
        try { $o['lengths'] = $this->lengths; } catch (Error $e) { }
        try { $o['num_rows'] = $this->num_rows; } catch (Error $e) { }
        try { $o['type'] = $this->type; } catch (Error $e) { }
        return $o;
    }

    public function __construct(mysqli $mysql, int $result_mode = 0)
    {
        $c = __McMysqliConn::of($mysql)->link();
        if ($result_mode !== 0 && $result_mode !== 1) {
            throw new ValueError('mysqli_result::__construct(): Argument #2 ($result_mode) must be either MYSQLI_STORE_RESULT or MYSQLI_USE_RESULT');
        }
        $this->__mc = $result_mode === 1 ? $c->takeUsed() : $c->takeStored();
    }

    public function __destruct()
    {
        if ($this->__mc !== null) { $this->__mc->free(); }
    }

    public function close(): void
    {
        __McMysqliRs::of($this)->free();
    }

    public function data_seek(int $offset): bool
    {
        return $this->__mcDataSeek($offset, 'mysqli_result::data_seek', 1);
    }

    /** data_seek() and mysqli_data_seek(): $fn and $arg (the offset's position) word the errors. */
    public function __mcDataSeek(int $offset, string $fn, int $arg): bool
    {
        $rs = __McMysqliRs::of($this);
        if ($offset < 0) { throw new ValueError($fn . '(): Argument #' . $arg . ' ($offset) must be greater than or equal to 0'); }
        if ($rs->unbuf) { throw new Error($fn . '() cannot be used in MYSQLI_USE_RESULT mode'); }
        $r = $rs->r;
        if ($r === null || $offset >= \count($r->rows)) { return false; }
        $rs->pos = $offset;
        $rs->cur = null;
        return true;
    }

    public function fetch_all(int $mode = 2): array
    {
        return $this->__mcFetchAll($mode, 'mysqli_result::fetch_all', 1);
    }

    /** @return array<int,mixed> */
    public function __mcFetchAll(int $mode, string $fn, int $arg): array
    {
        $rs = __McMysqliRs::of($this);
        if ($mode < 1 || $mode > 3) { throw __McMysqliRs::modeError($fn, $arg); }
        /** @var array<int,mixed> */
        $out = [];
        while (true) {
            $row = $rs->row();
            if ($row === null) { break; }
            if ($mode === 2) { $out[] = $row; }
            elseif ($mode === 1) { $out[] = $rs->assoc($row); }
            else { $out[] = $rs->both($row); }
        }
        return $out;
    }

    public function fetch_array(int $mode = 3): array|false|null
    {
        return $this->__mcFetchArray($mode, 'mysqli_result::fetch_array', 1);
    }

    public function __mcFetchArray(int $mode, string $fn, int $arg): array|null
    {
        $rs = __McMysqliRs::of($this);
        if ($mode < 1 || $mode > 3) { throw __McMysqliRs::modeError($fn, $arg); }
        $row = $rs->row();
        if ($row === null) { return null; }
        if ($mode === 2) { return $row; }
        if ($mode === 1) { return $rs->assoc($row); }
        return $rs->both($row);
    }

    public function fetch_assoc(): array|false|null
    {
        $rs = __McMysqliRs::of($this);
        $row = $rs->row();
        return $row === null ? null : $rs->assoc($row);
    }

    public function fetch_column(int $column = 0): false|float|int|string|null
    {
        return $this->__mcFetchColumn($column, 'mysqli_result::fetch_column', 1);
    }

    public function __mcFetchColumn(int $column, string $fn, int $arg): false|float|int|string|null
    {
        $rs = __McMysqliRs::of($this);
        if ($column < 0) { throw new ValueError($fn . '(): Argument #' . $arg . ' ($column) must be greater than or equal to 0'); }
        if ($column >= $rs->fields()) { throw new ValueError($fn . '(): Argument #' . $arg . ' ($column) must be less than the number of fields for this result set'); }
        $row = $rs->row();
        return $row === null ? false : $row[$column];
    }

    public function fetch_field(): false|object
    {
        return __McMysqliRs::of($this)->nextField();
    }

    public function fetch_field_direct(int $index): false|object
    {
        return $this->__mcFieldDirect($index, 'mysqli_result::fetch_field_direct', 1);
    }

    public function __mcFieldDirect(int $index, string $fn, int $arg): object
    {
        $rs = __McMysqliRs::of($this);
        if ($index < 0) { throw new ValueError($fn . '(): Argument #' . $arg . ' ($index) must be greater than or equal to 0'); }
        if ($index >= $rs->fields()) { throw new ValueError($fn . '(): Argument #' . $arg . ' ($index) must be less than the number of fields for this result set'); }
        return $rs->fieldObject($index);
    }

    /** @return array<int,object> */
    public function fetch_fields(): array
    {
        $rs = __McMysqliRs::of($this);
        $n = $rs->fields();
        /** @var array<int,object> */
        $out = [];
        for ($i = 0; $i < $n; $i++) { $out[] = $rs->fieldObject($i); }
        return $out;
    }

    /** @param mixed[] $constructor_args */
    public function fetch_object(string $class = 'stdClass', array $constructor_args = []): false|object|null
    {
        return $this->__mcFetchObject($class, $constructor_args, 'mysqli_result::fetch_object', 1);
    }

    /**
     * Zend's order: the class is looked up, a row is fetched (none: null), the object is created WITHOUT its
     * constructor, the columns become properties (a later column of the same name wins), then the constructor runs.
     *
     * @param mixed[] $args
     */
    public function __mcFetchObject(string $class, array $args, string $fn, int $arg): object|null
    {
        $rs = __McMysqliRs::of($this);
        $std = \strcasecmp($class, 'stdClass') === 0;
        if (!$std && !\class_exists($class) && !\interface_exists($class)) {
            throw new TypeError($fn . '(): Argument #' . $arg . ' ($class) must be a valid class name, ' . $class . ' given');
        }
        $row = $rs->row();
        $r = $rs->r;
        if ($row === null || $r === null) { return null; }
        $n = \count($r->names);
        if ($std) {
            if (\count($args) > 0) {
                throw new ValueError($fn . '(): Argument #' . ($arg + 1) . ' ($constructor_args) must be empty when the specified class (stdClass) does not have a constructor');
            }
            $o = new \stdClass();
            for ($i = 0; $i < $n; $i++) {
                $k = $r->names[$i];
                $o->$k = $row[$i];
            }
            return $o;
        }
        $ref = new \ReflectionClass($class);
        if ($ref->isAbstract() || $ref->isInterface()) { throw new Error('Class ' . $ref->getName() . ' cannot be instantiated'); }
        $o = $ref->newInstanceWithoutConstructor();
        __mc_mysqli_hydrate($o, $r, $row);
        if (\method_exists($o, '__construct')) {
            // an array that reaches the prelude has erased elements, and spreading it into a constructor hands that raw words:
            // copy it element by element into cells first (tests/aot/repro/wrong/spread_erased_array_into_dynamic_ctor.php)
            /** @var array<int,mixed> */
            $cells = [];
            foreach ($args as $v) { $cells[] = $v; }
            $o->__construct(...$cells);
        } elseif (\count($args) > 0) {
            throw new ValueError($fn . '(): Argument #' . ($arg + 1) . ' ($constructor_args) must be empty when the specified class (' . $ref->getName() . ') does not have a constructor');
        }
        return $o;
    }

    public function fetch_row(): array|false|null
    {
        return __McMysqliRs::of($this)->row();
    }

    public function field_seek(int $index): true
    {
        return $this->__mcFieldSeek($index, 'mysqli_result::field_seek', 1);
    }

    public function __mcFieldSeek(int $index, string $fn, int $arg): true
    {
        $rs = __McMysqliRs::of($this);
        if ($index < 0) { throw new ValueError($fn . '(): Argument #' . $arg . ' ($index) must be greater than or equal to 0'); }
        if ($index >= $rs->fields()) { throw new ValueError($fn . '(): Argument #' . $arg . ' ($index) must be less than the number of fields for this result set'); }
        $rs->field = $index;
        return true;
    }

    public function free(): void
    {
        __McMysqliRs::of($this)->free();
    }

    public function free_result(): void
    {
        __McMysqliRs::of($this)->free();
    }

    public function getIterator(): Iterator
    {
        return new __McMysqliIter($this);
    }
}

final class mysqli_sql_exception extends RuntimeException
{
    protected string $sqlstate = '00000';

    /** Internal factory (the surface audit skips `__mc*`): the only way in for the SQLSTATE, which Zend sets from C. */
    public static function __mcNew(string $message, int $code, string $state): self
    {
        $e = new self($message, $code);
        $e->sqlstate = $state;
        return $e;
    }

    public function getSqlState(): string
    {
        return $this->sqlstate;
    }
}

class mysqli_stmt
{
    public int|string $affected_rows;
    public int $errno;
    public string $error;
    public array $error_list;
    public int $field_count;
    public int $id;
    public int|string $insert_id;
    public int|string $num_rows;
    public int $param_count;
    public string $sqlstate;

    public function __construct(mysqli $mysql, ?string $query = null)
    {
        throw new Error('mysqli: not implemented');
    }

    public function attr_get(int $attribute): int
    {
        throw new Error('mysqli: not implemented');
    }

    public function attr_set(int $attribute, int $value): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function bind_result(mixed &...$vars): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function close(): true
    {
        throw new Error('mysqli: not implemented');
    }

    public function data_seek(int $offset): void
    {
        throw new Error('mysqli: not implemented');
    }

    public function execute(?array $params = null): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch(): ?bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function free_result(): void
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_result(): false|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function get_warnings(): false|mysqli_warning
    {
        throw new Error('mysqli: not implemented');
    }

    public function more_results(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function next_result(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function num_rows(): int|string
    {
        throw new Error('mysqli: not implemented');
    }

    public function prepare(string $query): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function reset(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function result_metadata(): false|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function send_long_data(int $param_num, string $data): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function store_result(): bool
    {
        throw new Error('mysqli: not implemented');
    }
}

final class mysqli_warning
{
    public string $message { get => $this->__msg[$this->__i]; set(mixed $v) { __McMysqliConn::ro($this, 'message'); } }
    public string $sqlstate { get => 'HY000'; set(mixed $v) { __McMysqliConn::ro($this, 'sqlstate'); } }
    public int $errno { get => $this->__no[$this->__i]; set(mixed $v) { __McMysqliConn::ro($this, 'errno'); } }

    /** @var array<int,string> */
    private array $__msg = [];
    /** @var array<int,int> */
    private array $__no = [];
    private int $__i = 0;

    private function __construct()
    {
    }

    /** Internal factory (the surface audit skips `__mc*`): the rows of one SHOW WARNINGS, at least one. */
    public static function __mcNew(array $msgs, array $nos): self
    {
        $w = new self();
        $w->__msg = $msgs;
        $w->__no = $nos;
        return $w;
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['message' => $this->message, 'sqlstate' => $this->sqlstate, 'errno' => $this->errno];
    }

    public function next(): bool
    {
        if ($this->__i + 1 >= \count($this->__msg)) { return false; }
        $this->__i++;
        return true;
    }
}
