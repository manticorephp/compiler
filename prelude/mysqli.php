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
        $w = $this->liveWire();
        $row = self::charsetRow($cs);
        if ($row === null) { return $this->fail(2019, 'HY000', 'Invalid character set was provided'); }
        $this->clear();
        try {
            $r = $w->query('SET NAMES ' . $cs);
        } catch (PDOException $e) {
            return $this->wireFail($e);
        }
        if ($r->err !== null) { return $this->cmd($r->err); }
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
        throw new Error('mysqli: not implemented');
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function change_user(string $username, #[\SensitiveParameter] string $password, ?string $database): bool
    {
        throw new Error('mysqli: not implemented');
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
        throw new Error('mysqli: not implemented');
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
        throw new Error('mysqli: not implemented');
    }

    public function init()
    {
        if ($this->__mcConn()->st === __McMysqliConn::CLOSED) { $this->__mc = new __McMysqliConn(); }
        return null;
    }

    public function kill(int $process_id): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function more_results(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function multi_query(string $query): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function next_result(): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function options(int $option, $value): bool
    {
        return __McMysqliConn::of($this)->option($option, $value);
    }

    public function ping(): bool
    {
        $c = __McMysqliConn::of($this);
        $w = $c->liveWire();
        $c->clear();
        return $w->ping() ? true : $c->fail(2006, 'HY000', 'MySQL server has gone away');
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
        throw new Error('mysqli: not implemented');
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
        throw new Error('mysqli: not implemented');
    }

    public function reap_async_query(): bool|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function refresh(int $flags): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function release_savepoint(string $name): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function savepoint(string $name): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function select_db(string $database): bool
    {
        $c = __McMysqliConn::of($this);
        $w = $c->liveWire();
        $c->clear();
        try {
            return $c->cmd($w->initDb($database));
        } catch (PDOException $e) {
            return $c->wireFail($e);
        }
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
        throw new Error('mysqli: not implemented');
    }

    public function stmt_init(): false|mysqli_stmt
    {
        throw new Error('mysqli: not implemented');
    }

    public function store_result(int $mode = 0): false|mysqli_result
    {
        throw new Error('mysqli: not implemented');
    }

    public function thread_safe(): bool
    {
        return true;
    }

    public function use_result(): false|mysqli_result
    {
        throw new Error('mysqli: not implemented');
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

class mysqli_result implements IteratorAggregate
{
    public int $current_field;
    public int $field_count;
    public ?array $lengths;
    public int|string $num_rows;
    public int $type;

    public function __construct(mysqli $mysql, int $result_mode = 0)
    {
        throw new Error('mysqli: not implemented');
    }

    public function close(): void
    {
        throw new Error('mysqli: not implemented');
    }

    public function data_seek(int $offset): bool
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_all(int $mode = 2): array
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_array(int $mode = 3): array|false|null
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_assoc(): array|false|null
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_column(int $column = 0): false|float|int|string|null
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_field(): false|object
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_field_direct(int $index): false|object
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_fields(): array
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_object(string $class = 'stdClass', array $constructor_args = []): false|object|null
    {
        throw new Error('mysqli: not implemented');
    }

    public function fetch_row(): array|false|null
    {
        throw new Error('mysqli: not implemented');
    }

    public function field_seek(int $index): true
    {
        throw new Error('mysqli: not implemented');
    }

    public function free(): void
    {
        throw new Error('mysqli: not implemented');
    }

    public function free_result(): void
    {
        throw new Error('mysqli: not implemented');
    }

    public function getIterator(): Iterator
    {
        throw new Error('mysqli: not implemented');
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
    public int $errno;
    public string $message;
    public string $sqlstate;

    private function __construct()
    {
        throw new Error('mysqli: not implemented');
    }

    public function next(): bool
    {
        throw new Error('mysqli: not implemented');
    }
}
