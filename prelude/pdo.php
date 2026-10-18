<?php

/**
 * ext/pdo — the driver-agnostic half of PHP Data Objects.
 *
 * WHY THE PRELUDE AND NOT src/Runtime/Stdlib: the stdlib `.o.sig` carries
 * FUNCTIONS ONLY. `new PDO(...)` hands back an object and `PDO::FETCH_ASSOC` is
 * a class constant — neither crosses a compiled-library boundary. A class
 * declared in the stdlib is never registered by the program holding one of its
 * instances, so `instanceof` reads false and its properties come back as raw
 * bits. Same call ext/curl, ext/simplexml and ext/dom made.
 *
 * THE DRIVER SEAM. `PDO` and `PDOStatement` hold no database knowledge at all;
 * they delegate to a {@see __McPdoDrv} / {@see __McPdoDrvStmt} pair. Everything
 * in this file — DSN parsing, the fetch modes, the attribute bag, the error
 * shapes — is shared by every driver. `pdo_sqlite.php` implements the seam over
 * libsqlite3 through FFI; a future mysql/pgsql driver implements the same two
 * interfaces over a socket with pack/unpack and needs no change here.
 *
 * The seam is deliberately shaped for a driver that must WAIT: `step()` returns
 * a row-or-not and is free to park the calling fiber, which is what a socket
 * driver needs and what the sqlite driver's SQLITE_BUSY backoff already does.
 *
 * ⚠ ORDER IS LOAD-BEARING inside this file. Classes are built in SOURCE order,
 * so a subclass parsed ahead of its parent inherits ZERO slots: the interfaces
 * come before their implementors, and `PDOStatement` before `__McPdoStmtIter`.
 *
 * DIVERGENCES FROM php, each deliberate and each documented in docs/pdo.md:
 *
 *  - `PDOException::getCode()` returns the DRIVER's integer code, never a
 *    SQLSTATE string. `Throwable::getCode(): int` is an interface contract here
 *    (prelude/exceptions.php), and php is itself inconsistent about this — a
 *    connect failure already yields int 14. The SQLSTATE is in `errorInfo[0]`
 *    and in the message, exactly where php puts them.
 *  - `bindColumn()` / `PDO::FETCH_BOUND` throw. Both exist only to write back
 *    into a caller's variable at fetch time, and a stored by-reference binding
 *    has no representation here — an array carries no is_ref bit.
 *  - `ATTR_PERSISTENT` is accepted and ignored: one process, no pool.
 */

// ── Exception ───────────────────────────────────────────────────────────────

class PDOException extends RuntimeException
{
    /**
     * `[SQLSTATE, driver code, driver message]`, php's shape exactly — built
     * ONCE by {@see __McPdo::fail} and never read back by this code.
     *
     * ⚠ Everything internal travels in the TYPED fields below instead. A
     * heterogeneous array stored in a property has its element channel erased
     * here, so reading `$info[0]` back returns raw bits — the bug that made
     * `new $cls()` instantiate a garbage class name. The array exists for
     * `$e->errorInfo`, which php programs read, and for nothing else.
     */
    public mixed $errorInfo = null;

    public string $sqlstate = '';
    public int $driverCode = 0;
    public bool $hasDriverCode = false;
    public string $driverMsg = '';
    public bool $hasDriverMsg = false;
}

// ── The driver seam ─────────────────────────────────────────────────────────

/**
 * One open connection, from the facade's point of view.
 *
 * Every method reports failure by THROWING a PDOException carrying a full
 * errorInfo triple. Translating that into php's silent / warning / exception
 * error modes is the facade's job, not the driver's, so a driver never has to
 * know which mode is in force.
 */
interface __McPdoDrv
{
    public function name(): string;

    public function prepare(string $sql): __McPdoDrvStmt;

    /** Runs one or more statements; answers the rows the LAST one changed. */
    public function exec(string $sql): int;

    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function inTransaction(): bool;

    public function lastInsertId(?string $name): string;
    public function quote(string $s, int $type): string|false;

    /** @return array<int,mixed> [SQLSTATE, code, message] */
    public function errorInfo(): array;

    /** False when the driver does not know the attribute at all. */
    public function setAttr(int $attr, mixed $value): bool;
    public function getAttr(int $attr): mixed;

    /**
     * Returns the session to the state a fresh connection has (superset, for Async\Pool): an open transaction is rolled
     * back, session variables, temporary tables and server-side prepared statements are dropped. Throws when the
     * connection cannot be reused.
     */
    public function reset(): void;

    /** True when the server still answers on this connection. Never throws. */
    public function ping(): bool;

    public function close(): void;
}

/** Optional: a statement whose driver can hold several results (mysql). */
interface __McPdoDrvMulti
{
    /** Moves to the next result of a multi-statement query; false when there is none. Throws if that statement failed. */
    public function nextRowset(): bool;
}

/**
 * One prepared statement.
 *
 * The cursor contract is php's, which is not the obvious one: `execute()` runs
 * the statement AND leaves the first row pending, so the first `step()` after it
 * yields that row rather than the second. That is what makes
 * `$db->exec()`-style statements report their row count immediately and what
 * makes `rowCount()` meaningful before any fetch.
 */
interface __McPdoDrvStmt
{
    public function paramCount(): int;

    /** 1-based; 0 when the name is not a placeholder in this statement. */
    public function paramIndex(string $name): int;

    public function bind(int $idx, mixed $value, int $type): void;

    public function execute(): void;

    /** Advance the cursor. False once the result set is exhausted. */
    public function step(): bool;

    public function columnCount(): int;
    public function columnName(int $i): string;

    /** @return array<string,mixed> */
    public function columnMeta(int $i): array;

    public function columnValue(int $i): mixed;

    public function rowCount(): int;

    public function closeCursor(): void;
    public function close(): void;

    /** @return array<int,mixed> */
    public function errorInfo(): array;
}

// ── Shared helpers ──────────────────────────────────────────────────────────

/**
 * Driver-agnostic scaffolding: the DSN parser, the SQLSTATE vocabulary and the
 * one place a failure turns into php's chosen error behaviour.
 */
final class __McPdo
{
    /**
     * php's `pdo_sqlstate_state_to_description` table (all 266 rows, measured through
     * a live server), one `CODE description` per line. An unlisted state is php's
     * `<<Unknown error>>`.
     */
    private const SQLSTATES = '
00000 No error
01000 Warning
01001 Cursor operation conflict
01002 Disconnect error
01003 NULL value eliminated in set function
01004 String data, right truncated
01006 Privilege not revoked
01007 Privilege not granted
01008 Implicit zero bit padding
0100C Dynamic result sets returned
01P01 Deprecated feature
01S00 Invalid connection string attribute
01S01 Error in row
01S02 Option value changed
01S06 Attempt to fetch before the result set returned the first rowset
01S07 Fractional truncation
01S08 Error saving File DSN
01S09 Invalid keyword
02000 No data
02001 No additional dynamic result sets returned
03000 Sql statement not yet complete
07002 COUNT field incorrect
07005 Prepared statement not a cursor-specification
07006 Restricted data type attribute violation
07009 Invalid descriptor index
07S01 Invalid use of default parameter
08000 Connection exception
08001 Client unable to establish connection
08002 Connection name in use
08003 Connection does not exist
08004 Server rejected the connection
08006 Connection failure
08007 Connection failure during transaction
08S01 Communication link failure
09000 Triggered action exception
0A000 Feature not supported
0B000 Invalid transaction initiation
0F000 Locator exception
0F001 Invalid locator specification
0L000 Invalid grantor
0LP01 Invalid grant operation
0P000 Invalid role specification
21000 Cardinality violation
21S01 Insert value list does not match column list
21S02 Degree of derived table does not match column list
22000 Data exception
22001 String data, right truncated
22002 Indicator variable required but not supplied
22003 Numeric value out of range
22004 Null value not allowed
22005 Error in assignment
22007 Invalid datetime format
22008 Datetime field overflow
22009 Invalid time zone displacement value
2200B Escape character conflict
2200C Invalid use of escape character
2200D Invalid escape octet
2200F Zero length character string
2200G Most specific type mismatch
22010 Invalid indicator parameter value
22011 Substring error
22012 Division by zero
22015 Interval field overflow
22018 Invalid character value for cast specification
22019 Invalid escape character
2201B Invalid regular expression
2201E Invalid argument for logarithm
2201F Invalid argument for power function
2201G Invalid argument for width bucket function
22020 Invalid limit value
22021 Character not in repertoire
22022 Indicator overflow
22023 Invalid parameter value
22024 Unterminated c string
22025 Invalid escape sequence
22026 String data, length mismatch
22027 Trim error
2202E Array subscript error
22P01 Floating point exception
22P02 Invalid text representation
22P03 Invalid binary representation
22P04 Bad copy file format
22P05 Untranslatable character
23000 Integrity constraint violation
23001 Restrict violation
23502 Not null violation
23503 Foreign key violation
23505 Unique violation
23514 Check violation
24000 Invalid cursor state
25000 Invalid transaction state
25001 Active sql transaction
25002 Branch transaction already active
25003 Inappropriate access mode for branch transaction
25004 Inappropriate isolation level for branch transaction
25005 No active sql transaction for branch transaction
25006 Read only sql transaction
25007 Schema and data statement mixing not supported
25008 Held cursor requires same isolation level
25P01 No active sql transaction
25P02 In failed sql transaction
25S01 Transaction state
25S02 Transaction is still active
25S03 Transaction is rolled back
26000 Invalid sql statement name
27000 Triggered data change violation
28000 Invalid authorization specification
2B000 Dependent privilege descriptors still exist
2BP01 Dependent objects still exist
2D000 Invalid transaction termination
2F000 Sql routine exception
2F002 Modifying sql data not permitted
2F003 Prohibited sql statement attempted
2F004 Reading sql data not permitted
2F005 Function executed no return statement
34000 Invalid cursor name
38000 External routine exception
38001 Containing sql not permitted
38002 Modifying sql data not permitted
38003 Prohibited sql statement attempted
38004 Reading sql data not permitted
39000 External routine invocation exception
39001 Invalid sqlstate returned
39004 Null value not allowed
39P01 Trigger protocol violated
39P02 Srf protocol violated
3B000 Savepoint exception
3B001 Invalid savepoint specification
3C000 Duplicate cursor name
3D000 Invalid catalog name
3F000 Invalid schema name
40000 Transaction rollback
40001 Serialization failure
40002 Transaction integrity constraint violation
40003 Statement completion unknown
40P01 Deadlock detected
42000 Syntax error or access violation
42501 Insufficient privilege
42601 Syntax error
42602 Invalid name
42611 Invalid column definition
42622 Name too long
42701 Duplicate column
42702 Ambiguous column
42703 Undefined column
42704 Undefined object
42710 Duplicate object
42712 Duplicate alias
42723 Duplicate function
42725 Ambiguous function
42803 Grouping error
42804 Datatype mismatch
42809 Wrong object type
42830 Invalid foreign key
42846 Cannot coerce
42883 Undefined function
42939 Reserved name
42P01 Undefined table
42P02 Undefined parameter
42P03 Duplicate cursor
42P04 Duplicate database
42P05 Duplicate prepared statement
42P06 Duplicate schema
42P07 Duplicate table
42P08 Ambiguous parameter
42P09 Ambiguous alias
42P10 Invalid column reference
42P11 Invalid cursor definition
42P12 Invalid database definition
42P13 Invalid function definition
42P14 Invalid prepared statement definition
42P15 Invalid schema definition
42P16 Invalid table definition
42P17 Invalid object definition
42P18 Indeterminate datatype
42S01 Base table or view already exists
42S02 Base table or view not found
42S11 Index already exists
42S12 Index not found
42S21 Column already exists
42S22 Column not found
44000 WITH CHECK OPTION violation
53000 Insufficient resources
53100 Disk full
53200 Out of memory
53300 Too many connections
54000 Program limit exceeded
54001 Statement too complex
54011 Too many columns
54023 Too many arguments
55000 Object not in prerequisite state
55006 Object in use
55P02 Cant change runtime param
55P03 Lock not available
57000 Operator intervention
57014 Query canceled
57P01 Admin shutdown
57P02 Crash shutdown
57P03 Cannot connect now
58030 Io error
58P01 Undefined file
58P02 Duplicate file
F0000 Config file error
F0001 Lock file exists
HY000 General error
HY001 Memory allocation error
HY003 Invalid application buffer type
HY004 Invalid SQL data type
HY007 Associated statement is not prepared
HY008 Operation canceled
HY009 Invalid use of null pointer
HY010 Function sequence error
HY011 Attribute cannot be set now
HY012 Invalid transaction operation code
HY013 Memory management error
HY014 Limit on the number of handles exceeded
HY015 No cursor name available
HY016 Cannot modify an implementation row descriptor
HY017 Invalid use of an automatically allocated descriptor handle
HY018 Server declined cancel request
HY019 Non-character and non-binary data sent in pieces
HY020 Attempt to concatenate a null value
HY021 Inconsistent descriptor information
HY024 Invalid attribute value
HY090 Invalid string or buffer length
HY091 Invalid descriptor field identifier
HY092 Invalid attribute/option identifier
HY093 Invalid parameter number
HY095 Function type out of range
HY096 Invalid information type
HY097 Column type out of range
HY098 Scope type out of range
HY099 Nullable type out of range
HY100 Uniqueness option type out of range
HY101 Accuracy option type out of range
HY103 Invalid retrieval code
HY104 Invalid precision or scale value
HY105 Invalid parameter type
HY106 Fetch type out of range
HY107 Row value out of range
HY109 Invalid cursor position
HY110 Invalid driver completion
HY111 Invalid bookmark value
HYC00 Optional feature not implemented
HYT00 Timeout expired
HYT01 Connection timeout expired
IM001 Driver does not support this function
IM002 Data source name not found and no default driver specified
IM003 Specified driver could not be loaded
IM004 Driver\'s SQLAllocHandle on SQL_HANDLE_ENV failed
IM005 Driver\'s SQLAllocHandle on SQL_HANDLE_DBC failed
IM006 Driver\'s SQLSetConnectAttr failed
IM007 No data source or driver specified; dialog prohibited
IM008 Dialog failed
IM009 Unable to load translation DLL
IM010 Data source name too long
IM011 Driver name too long
IM012 DRIVER keyword syntax error
IM013 Trace file error
IM014 Invalid name of File DSN
IM015 Corrupt file data source
P0000 Plpgsql error
P0001 Raise exception
XX000 Internal error
XX001 Data corrupted
XX002 Index corrupted
';

    public static function sqlstateText(string $state): string
    {
        // the state may come off the wire: only a well-formed one is looked up
        if (\strlen($state) !== 5 || \strspn($state, '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ') !== 5) { return '<<Unknown error>>'; }
        $i = \strpos(self::SQLSTATES, "\n" . $state . ' ');
        if ($i === false) { return '<<Unknown error>>'; }
        $s = $i + 2 + \strlen($state);
        return \substr(self::SQLSTATES, $s, \strpos(self::SQLSTATES, "\n", $s) - $s);
    }

    /**
     * php's message shape: `SQLSTATE[HY000]: General error: 1 near "x": syntax
     * error`. The driver code and message are omitted when the driver had
     * nothing to say, which is how the IM001 "unsupported" failures read.
     */
    public static function message(string $state, int $code, bool $hasCode,
                                   string $msg, bool $hasMsg): string
    {
        $s = 'SQLSTATE[' . $state . ']: ' . self::sqlstateText($state);
        if (!$hasMsg) { return $s; }
        if (!$hasCode) { return $s . ': ' . $msg; }
        return $s . ': ' . (string) $code . ' ' . $msg;
    }

    /**
     * The one place a PDOException is built, so its typed fields and its
     * php-facing `errorInfo` array can never disagree.
     */
    public static function fail(string $state, int $code, bool $hasCode,
                                string $msg, bool $hasMsg): PDOException
    {
        $e = new PDOException(self::message($state, $code, $hasCode, $msg, $hasMsg),
                              $hasCode ? $code : 0);
        $e->sqlstate = $state;
        $e->driverCode = $code;
        $e->hasDriverCode = $hasCode;
        $e->driverMsg = $msg;
        $e->hasDriverMsg = $hasMsg;
        $e->errorInfo = self::triple($state, $code, $hasCode, $msg, $hasMsg);
        return $e;
    }

    /**
     * A CONNECT failure, which php formats differently from every other one:
     * `SQLSTATE[HY000] [14] unable to open database file` — no description, no
     * colon, the driver code in brackets. Only PDO::__construct produces it.
     */
    public static function connectFail(string $state, int $code, string $msg): PDOException
    {
        $e = new PDOException('SQLSTATE[' . $state . '] [' . (string) $code . '] ' . $msg, $code);
        $e->sqlstate = $state;
        $e->driverCode = $code;
        $e->hasDriverCode = true;
        $e->driverMsg = $msg;
        $e->hasDriverMsg = true;
        $e->errorInfo = self::triple($state, $code, true, $msg, true);
        return $e;
    }

    /** @return array<int,mixed> php's `[SQLSTATE, code, message]` shape */
    public static function triple(string $state, int $code, bool $hasCode,
                                  string $msg, bool $hasMsg): array
    {
        if ($hasCode && $hasMsg) { return [$state, $code, $msg]; }
        if ($hasMsg) { return [$state, null, $msg]; }
        if ($hasCode) { return [$state, $code, null]; }
        return [$state, null, null];
    }

    /**
     * Turn a driver failure into php's chosen behaviour.
     *
     * ⚠ ERRMODE_WARNING is the one place the project's "throw where Zend warns"
     * rule does NOT apply. The program explicitly asked for a warning by setting
     * the attribute, which makes this the deliberate-diagnostic case that
     * prelude/errors.php carves out for `trigger_error` — so it stays
     * Zend-shaped rather than becoming an exception.
     */
    public static function raise(int $errmode, string $state, int $code, bool $hasCode,
                                 string $msg, bool $hasMsg, string $fn = 'PDO::errorInfo'): void
    {
        if ($errmode === PDO::ERRMODE_EXCEPTION) {
            throw self::fail($state, $code, $hasCode, $msg, $hasMsg);
        }
        if ($errmode === PDO::ERRMODE_WARNING) {
            \trigger_error($fn . '(): '
                . self::message($state, $code, $hasCode, $msg, $hasMsg), \E_USER_WARNING);
        }
    }

    /**
     * `scheme:rest` → `[scheme, rest]`. php also accepts a URI or an ini alias
     * as the whole DSN; neither is implemented, and both are named at the call
     * site rather than silently mistaken for a scheme.
     */
    public static function splitDsn(string $dsn): array
    {
        $i = \strpos($dsn, ':');
        if ($i === false || $i === 0) { return ['', '']; }
        return [\substr($dsn, 0, $i), \substr($dsn, $i + 1)];
    }

    /**
     * The `key=value;key=value` half of a DSN.
     *
     * @return array<string,string>
     */
    public static function parseParams(string $rest): array
    {
        /** @var array<string,string> */
        $out = [];
        $parts = \explode(';', $rest);
        foreach ($parts as $p) {
            if ($p === '') { continue; }
            $eq = \strpos($p, '=');
            if ($eq === false) { continue; }
            $out[\trim(\substr($p, 0, $eq))] = \trim(\substr($p, $eq + 1));
        }
        return $out;
    }

    /** php lowercases / uppercases the column KEY, never the value. */
    public static function applyCase(string $name, int $case): string
    {
        if ($case === PDO::CASE_LOWER) { return \strtolower($name); }
        if ($case === PDO::CASE_UPPER) { return \strtoupper($name); }
        return $name;
    }

    /** Call any callable shape with a runtime-sized argument list. */
    public static function callArgs(mixed $cb, array $args): mixed
    {
        if (\is_array($cb)) {
            $o = $cb[0];
            $m = $cb[1];
            return $o->$m(...$args);
        }
        return $cb(...$args);
    }
}

// ── PDO ─────────────────────────────────────────────────────────────────────

#[\Manticore\Attr\Uncomparable]
class PDO
{
    public const PARAM_NULL = 0;
    public const PARAM_BOOL = 5;
    public const PARAM_INT = 1;
    public const PARAM_STR = 2;
    public const PARAM_LOB = 3;
    public const PARAM_STMT = 4;
    public const PARAM_STR_NATL = 1073741824;
    public const PARAM_STR_CHAR = 536870912;
    public const PARAM_INPUT_OUTPUT = 2147483648;

    public const PARAM_EVT_ALLOC = 0;
    public const PARAM_EVT_FREE = 1;
    public const PARAM_EVT_EXEC_PRE = 2;
    public const PARAM_EVT_EXEC_POST = 3;
    public const PARAM_EVT_FETCH_PRE = 4;
    public const PARAM_EVT_FETCH_POST = 5;
    public const PARAM_EVT_NORMALIZE = 6;

    public const FETCH_DEFAULT = 0;
    public const FETCH_LAZY = 1;
    public const FETCH_ASSOC = 2;
    public const FETCH_NUM = 3;
    public const FETCH_BOTH = 4;
    public const FETCH_OBJ = 5;
    public const FETCH_BOUND = 6;
    public const FETCH_COLUMN = 7;
    public const FETCH_CLASS = 8;
    public const FETCH_INTO = 9;
    public const FETCH_FUNC = 10;
    public const FETCH_NAMED = 11;
    public const FETCH_KEY_PAIR = 12;

    public const FETCH_GROUP = 32;
    public const FETCH_UNIQUE = 64;
    public const FETCH_CLASSTYPE = 128;
    public const FETCH_PROPS_LATE = 256;
    public const FETCH_SERIALIZE = 512;

    public const ATTR_AUTOCOMMIT = 0;
    public const ATTR_PREFETCH = 1;
    public const ATTR_TIMEOUT = 2;
    public const ATTR_ERRMODE = 3;
    public const ATTR_SERVER_VERSION = 4;
    public const ATTR_CLIENT_VERSION = 5;
    public const ATTR_SERVER_INFO = 6;
    public const ATTR_CONNECTION_STATUS = 7;
    public const ATTR_CASE = 8;
    public const ATTR_CURSOR_NAME = 9;
    public const ATTR_CURSOR = 10;
    public const ATTR_ORACLE_NULLS = 11;
    public const ATTR_PERSISTENT = 12;
    public const ATTR_STATEMENT_CLASS = 13;
    public const ATTR_FETCH_TABLE_NAMES = 14;
    public const ATTR_FETCH_CATALOG_NAMES = 15;
    public const ATTR_DRIVER_NAME = 16;
    public const ATTR_STRINGIFY_FETCHES = 17;
    public const ATTR_MAX_COLUMN_LEN = 18;
    public const ATTR_DEFAULT_FETCH_MODE = 19;
    public const ATTR_EMULATE_PREPARES = 20;
    public const ATTR_DEFAULT_STR_PARAM = 21;

    public const ERRMODE_SILENT = 0;
    public const ERRMODE_WARNING = 1;
    public const ERRMODE_EXCEPTION = 2;

    public const CASE_NATURAL = 0;
    public const CASE_UPPER = 1;
    public const CASE_LOWER = 2;

    public const NULL_NATURAL = 0;
    public const NULL_EMPTY_STRING = 1;
    public const NULL_TO_STRING = 2;

    public const FETCH_ORI_NEXT = 0;
    public const FETCH_ORI_PRIOR = 1;
    public const FETCH_ORI_FIRST = 2;
    public const FETCH_ORI_LAST = 3;
    public const FETCH_ORI_ABS = 4;
    public const FETCH_ORI_REL = 5;

    public const CURSOR_FWDONLY = 0;
    public const CURSOR_SCROLL = 1;

    public const ERR_NONE = '00000';

    /**
     * Driver-specific attribute ids. php declares them on PDO even though only
     * the sqlite driver ever reads them, because a `.sig` for constants does not
     * exist there either.
     */
    public const PGSQL_ATTR_DISABLE_PREPARES = 1000;
    /** Manticore superset (php has none): rows per portal Execute, 0 = buffered. */
    public const PGSQL_ATTR_FETCH_BATCH = 1300;
    public const PGSQL_TRANSACTION_IDLE = 0;
    public const PGSQL_TRANSACTION_ACTIVE = 1;
    public const PGSQL_TRANSACTION_INTRANS = 2;
    public const PGSQL_TRANSACTION_INERROR = 3;
    public const PGSQL_TRANSACTION_UNKNOWN = 4;
    public const MYSQL_ATTR_USE_BUFFERED_QUERY = 1000;
    public const MYSQL_ATTR_LOCAL_INFILE = 1001;
    public const MYSQL_ATTR_INIT_COMMAND = 1002;
    public const MYSQL_ATTR_COMPRESS = 1003;
    public const MYSQL_ATTR_DIRECT_QUERY = 20;
    public const MYSQL_ATTR_FOUND_ROWS = 1004;
    public const MYSQL_ATTR_IGNORE_SPACE = 1005;
    public const MYSQL_ATTR_SSL_KEY = 1006;
    public const MYSQL_ATTR_SSL_CERT = 1007;
    public const MYSQL_ATTR_SSL_CA = 1008;
    public const MYSQL_ATTR_SSL_CAPATH = 1009;
    public const MYSQL_ATTR_SSL_CIPHER = 1010;
    public const MYSQL_ATTR_SERVER_PUBLIC_KEY = 1011;
    public const MYSQL_ATTR_MULTI_STATEMENTS = 1012;
    public const MYSQL_ATTR_SSL_VERIFY_SERVER_CERT = 1013;
    public const MYSQL_ATTR_LOCAL_INFILE_DIRECTORY = 1014;
    public const SQLITE_ATTR_OPEN_FLAGS = 1000;
    public const SQLITE_ATTR_READONLY_STATEMENT = 1001;
    public const SQLITE_ATTR_EXTENDED_RESULT_CODES = 1002;
    public const SQLITE_OPEN_READONLY = 1;
    public const SQLITE_OPEN_READWRITE = 2;
    public const SQLITE_OPEN_CREATE = 4;
    public const SQLITE_DETERMINISTIC = 2048;

    public ?__McPdoDrv $drv = null;

    public int $errmode = 2;
    /** Method name that overrides the caller's in a warning (query() runs prepare+execute). */
    /** The method a warning names while query() runs prepare()+execute(); private so the public surface stays php's. */
    private string $label = '';
    public int $case = 0;
    public int $oracleNulls = 0;
    public bool $stringify = false;
    public int $defaultFetch = 4;
    public bool $persistent = false;

    /**
     * The last failure, as TYPED fields rather than php's triple.
     *
     * ⚠ Storing the triple as an array would erase its element channel — it is
     * heterogeneous by construction (string, ?int, ?string) — so every read back
     * would return raw bits. The array is materialised only by errorInfo().
     */
    public string $errState = '';
    public int $errCode = 0;
    public bool $errHasCode = false;
    public string $errMsg = '';
    public bool $errHasMsg = false;

    /** ATTR_STATEMENT_CLASS, split for the same reason: a `[name, args]` pair
     *  is heterogeneous, and reading element 0 back out of it produced a garbage
     *  class name that `new $cls()` then instantiated. */
    public string $stmtClassName = 'PDOStatement';
    /** @var array<int,mixed> */
    public array $stmtClassArgs = [];

    public function __construct(string $dsn, ?string $username = null,
                                ?string $password = null, ?array $options = null)
    {
        $parts = __McPdo::splitDsn($dsn);
        $scheme = $parts[0];
        $rest = $parts[1];

        /*
         * The driver registry is a `match` and not a lookup table on purpose:
         * every driver is LINKED IN at compile time, and the analyzer runs
         * closed-world, so a name it cannot resolve is a compile error rather
         * than a runtime surprise. Adding a driver means adding one arm here and
         * splitting the prelude gate in Main.php the same way.
         */
        if ($scheme === 'sqlite') {
            $this->drv = __mc_pdo_sqlite_open($rest, $options === null ? [] : $options);
        } elseif ($scheme === 'pgsql') {
            $this->drv = __mc_pdo_pgsql_open($rest, $options === null ? [] : $options, $username, $password);
        } elseif ($scheme === 'mysql') {
            $this->drv = __mc_pdo_mysql_open($rest, $options === null ? [] : $options, $username, $password);
        } else {
            // php reports an unknown scheme with no SQLSTATE and no driver code,
            // and its message carries no SQLSTATE prefix either.
            $e = new PDOException('could not find driver', 0);
            $e->errorInfo = __McPdo::triple('', 0, false, '', false);
            throw $e;
        }

        if ($options !== null) {
            foreach ($options as $k => $v) {
                if ($k === PDO::ATTR_PERSISTENT) { $this->persistent = (bool) $v; }
                elseif (\is_int($k)) { $this->setAttribute($k, $v); }
            }
        }
    }

    /** php 8.4's named constructor. `new static` so a subclass stays itself. */
    public static function connect(string $dsn, ?string $username = null,
                                   ?string $password = null, ?array $options = null): static
    {
        return new static($dsn, $username, $password, $options);
    }

    /** Every driver this binary was linked against. */
    public static function getAvailableDrivers(): array
    {
        return ['mysql', 'pgsql', 'sqlite'];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            $ds = $this->drv->prepare($query);
        } catch (PDOException $e) {
            $this->note($e, 'PDO::prepare');
            return false;
        }
        $this->clearErr();
        return $this->makeStatement($ds, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        // php names query() in the warning, not the prepare()/execute() it is made of
        $this->label = 'PDO::query';
        try {
            $st = $this->prepare($query);
            if ($st === false) { return false; }
            if ($fetchMode !== null) { $st->setFetchMode($fetchMode, ...$fetchModeArgs); }
            $ok = $st->execute();
        } finally {
            $this->label = '';
        }
        if ($ok === false) { return false; }
        return $st;
    }

    public function exec(string $statement): mixed
    {
        try {
            $n = $this->drv->exec($statement);
        } catch (PDOException $e) {
            return $this->note($e, 'PDO::exec');
        }
        $this->clearErr();
        return $n;
    }

    public function beginTransaction(): bool
    {
        if ($this->drv->inTransaction()) {
            throw new PDOException('There is already an active transaction', 0);
        }
        return $this->run('begin', 'PDO::beginTransaction');
    }

    public function commit(): bool
    {
        if (!$this->drv->inTransaction()) {
            throw new PDOException('There is no active transaction', 0);
        }
        return $this->run('commit', 'PDO::commit');
    }

    public function rollBack(): bool
    {
        if (!$this->drv->inTransaction()) {
            throw new PDOException('There is no active transaction', 0);
        }
        return $this->run('rollback', 'PDO::rollBack');
    }

    public function inTransaction(): bool
    {
        return $this->drv->inTransaction();
    }

    /**
     * Superset (no Zend oracle): returns the session to a fresh-connection state, see {@see __McPdoDrv::reset()}.
     * Statements prepared before the call are invalid afterwards.
     */
    public function resetSession(): void
    {
        try {
            $this->drv->reset();
        } catch (PDOException $e) {
            $this->note($e, 'PDO::resetSession');
        }
    }

    /** Superset: true when the server still answers; a lost connection is false, never an exception. */
    public function ping(): bool
    {
        return $this->drv->ping();
    }

    public function lastInsertId(?string $name = null): mixed
    {
        try {
            return $this->drv->lastInsertId($name);
        } catch (PDOException $e) {
            return $this->note($e, 'PDO::lastInsertId');
        }
    }

    public function quote(string $string, int $type = 2): mixed
    {
        try {
            return $this->drv->quote($string, $type);
        } catch (PDOException $e) {
            return $this->note($e, 'PDO::quote');
        }
    }

    public function errorCode(): mixed
    {
        return $this->errState === '' ? null : $this->errState;
    }

    public function errorInfo(): array
    {
        return __McPdo::triple($this->errState, $this->errCode, $this->errHasCode,
                               $this->errMsg, $this->errHasMsg);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->clearErr();
        if ($attribute === PDO::ATTR_ERRMODE) { $this->errmode = (int) $value; return true; }
        if ($attribute === PDO::ATTR_CASE) { $this->case = (int) $value; return true; }
        if ($attribute === PDO::ATTR_ORACLE_NULLS) { $this->oracleNulls = (int) $value; return true; }
        if ($attribute === PDO::ATTR_STRINGIFY_FETCHES) { $this->stringify = (bool) $value; return true; }
        if ($attribute === PDO::ATTR_DEFAULT_FETCH_MODE) { $this->defaultFetch = (int) $value; return true; }
        // Accepted and ignored: one process, no connection pool to keep.
        // php (measured, mysql pgsql sqlite): answered false once connected; only the constructor option is kept
        if ($attribute === PDO::ATTR_PERSISTENT) { return false; }
        // Consumed by the driver's open(); accepted so it can ride the options
        // array without then being reported as unsupported.
        if ($attribute === PDO::SQLITE_ATTR_OPEN_FLAGS && $this->drv->name() === 'sqlite') { return true; }
        if ($attribute === PDO::ATTR_STATEMENT_CLASS) {
            if (\is_array($value)) {
                $this->stmtClassName = (string) $value[0];
                $this->stmtClassArgs = isset($value[1]) && \is_array($value[1])
                    ? $value[1] : [];
            } else {
                $this->stmtClassName = (string) $value;
                $this->stmtClassArgs = [];
            }
            return true;
        }
        // php (measured, sqlite and pgsql): a refused set is a plain false, no error recorded
        try {
            return $this->drv->setAttr($attribute, $value);
        } catch (PDOException $e) {
            $this->note($e, 'PDO::setAttribute');
            return false;
        }
    }

    public function getAttribute(int $attribute): mixed
    {
        $this->clearErr();
        if ($attribute === PDO::ATTR_ERRMODE) { return $this->errmode; }
        if ($attribute === PDO::ATTR_CASE) { return $this->case; }
        if ($attribute === PDO::ATTR_ORACLE_NULLS) { return $this->oracleNulls; }
        if ($attribute === PDO::ATTR_STRINGIFY_FETCHES) { return $this->stringify; }
        if ($attribute === PDO::ATTR_DEFAULT_FETCH_MODE) { return $this->defaultFetch; }
        if ($attribute === PDO::ATTR_PERSISTENT) { return $this->persistent; }
        if ($attribute === PDO::ATTR_STATEMENT_CLASS) {
            return [$this->stmtClassName, $this->stmtClassArgs];
        }
        if ($attribute === PDO::ATTR_DRIVER_NAME) { return $this->drv->name(); }
        try {
            $v = $this->drv->getAttr($attribute);
        } catch (PDOException $e) {
            return $this->note($e, 'PDO::getAttribute');
        }
        if ($v !== null) { return $v; }
        return $this->unsupported('driver does not support that attribute', 'PDO::getAttribute');
    }

    // ── pgsql extras: php 8.4 names, plus the pgsql* aliases php keeps on PDO (no deprecation is raised) ──

    /** The pgsql driver, or php's own answer for a method the connected driver does not have. */
    private function pg(string $method): __McPdoPg
    {
        $d = $this->drv;
        if ($d instanceof __McPdoPg) { return $d; }
        throw new Error('Call to undefined method PDO::' . $method . '()');
    }

    /**
     * Waits (a fiber parks, the loop keeps running) up to $timeoutMilliseconds for a LISTEN notification; 0 only looks.
     * The row is [message => channel, pid, payload] in ASSOC, [0, 1, 2] in NUM, both in BOTH (the default); payload only when non-empty.
     */
    public function getNotify(int $fetchMode = PDO::FETCH_DEFAULT, int $timeoutMilliseconds = 0): array|false
    {
        $pg = $this->pg('getNotify');
        if ($fetchMode === PDO::FETCH_DEFAULT) { $fetchMode = PDO::FETCH_BOTH; }
        if ($fetchMode !== PDO::FETCH_BOTH && $fetchMode !== PDO::FETCH_ASSOC && $fetchMode !== PDO::FETCH_NUM) {
            throw new ValueError('PDO::getNotify(): Argument #1 ($fetchMode) must be one of PDO::FETCH_BOTH, PDO::FETCH_ASSOC, or PDO::FETCH_NUM');
        }
        if ($timeoutMilliseconds < 0) {
            throw new ValueError('PDO::getNotify(): Argument #2 ($timeoutMilliseconds) must be greater than or equal to 0');
        }
        if ($timeoutMilliseconds > 2147483647) {
            throw new ValueError('PDO::getNotify(): Argument #2 ($timeoutMilliseconds) must be less than or equal to 2147483647');
        }
        try {
            $n = $pg->notify($timeoutMilliseconds);
        } catch (PDOException $e) {
            $this->note($e, 'PDO::getNotify');
            return false;
        }
        $this->clearErr();
        if ($n === null) { return false; }
        return $this->notifyRow($n, $fetchMode);
    }

    /** @return array<string,mixed> */
    private function notifyRow(__McPdoPgNotify $n, int $mode): array
    {
        /** @var array<string,mixed> */
        $r = [];
        if ($mode !== PDO::FETCH_ASSOC) {
            $r[0] = $n->channel;
            $r[1] = $n->pid;
            if ($n->payload !== '') { $r[2] = $n->payload; }
        }
        if ($mode !== PDO::FETCH_NUM) {
            $r['message'] = $n->channel;
            $r['pid'] = $n->pid;
            if ($n->payload !== '') { $r['payload'] = $n->payload; }
        }
        return $r;
    }

    public function getPid(): int { return $this->pg('getPid')->pid(); }

    /** @param Traversable<mixed,mixed>|array<mixed,mixed> $rows each row is one text-format COPY line; a newline is added when missing */
    public function copyFromArray(string $tableName, Traversable|array $rows, string $separator = "\t",
                                  string $nullAs = '\\\\N', ?string $fields = null): bool
    {
        $pg = $this->pg('copyFromArray');
        try {
            $pg->copyFromRows($tableName, $rows, $separator, $nullAs, $fields);
        } catch (PDOException $e) {
            $this->note($e, 'PDO::copyFromArray');
            return false;
        }
        $this->clearErr();
        return true;
    }

    public function copyFromFile(string $tableName, string $filename, string $separator = "\t",
                                 string $nullAs = '\\\\N', ?string $fields = null): bool
    {
        $pg = $this->pg('copyFromFile');
        try {
            $pg->copyFromFile($tableName, $filename, $separator, $nullAs, $fields);
        } catch (PDOException $e) {
            $this->note($e, 'PDO::copyFromFile');
            return false;
        }
        $this->clearErr();
        return true;
    }

    /** @return array<int,string>|false one element per row, trailing newline included */
    public function copyToArray(string $tableName, string $separator = "\t", string $nullAs = '\\\\N', ?string $fields = null): array|false
    {
        $pg = $this->pg('copyToArray');
        try {
            $rows = $pg->copyToRows($tableName, $separator, $nullAs, $fields);
        } catch (PDOException $e) {
            $this->note($e, 'PDO::copyToArray');
            return false;
        }
        $this->clearErr();
        return $rows;
    }

    public function copyToFile(string $tableName, string $filename, string $separator = "\t",
                               string $nullAs = '\\\\N', ?string $fields = null): bool
    {
        $pg = $this->pg('copyToFile');
        try {
            $pg->copyToFile($tableName, $filename, $separator, $nullAs, $fields);
        } catch (PDOException $e) {
            $this->note($e, 'PDO::copyToFile');
            return false;
        }
        $this->clearErr();
        return true;
    }

    public function pgsqlGetNotify(int $fetchMode = PDO::FETCH_DEFAULT, int $timeoutMilliseconds = 0): array|false
    {
        $this->pg('pgsqlGetNotify');
        return $this->getNotify($fetchMode, $timeoutMilliseconds);
    }

    public function pgsqlGetPid(): int { return $this->pg('pgsqlGetPid')->pid(); }

    public function pgsqlCopyFromArray(string $tableName, Traversable|array $rows, string $separator = "\t",
                                       string $nullAs = '\\\\N', ?string $fields = null): bool
    {
        $this->pg('pgsqlCopyFromArray');
        return $this->copyFromArray($tableName, $rows, $separator, $nullAs, $fields);
    }

    public function pgsqlCopyFromFile(string $tableName, string $filename, string $separator = "\t",
                                      string $nullAs = '\\\\N', ?string $fields = null): bool
    {
        $this->pg('pgsqlCopyFromFile');
        return $this->copyFromFile($tableName, $filename, $separator, $nullAs, $fields);
    }

    /** @return array<int,string>|false */
    public function pgsqlCopyToArray(string $tableName, string $separator = "\t", string $nullAs = '\\\\N', ?string $fields = null): array|false
    {
        $this->pg('pgsqlCopyToArray');
        return $this->copyToArray($tableName, $separator, $nullAs, $fields);
    }

    public function pgsqlCopyToFile(string $tableName, string $filename, string $separator = "\t",
                                    string $nullAs = '\\\\N', ?string $fields = null): bool
    {
        $this->pg('pgsqlCopyToFile');
        return $this->copyToFile($tableName, $filename, $separator, $nullAs, $fields);
    }

    /** Shared body of begin/commit/rollback, so all three report identically. */
    private function run(string $what, string $fn): bool
    {
        try {
            if ($what === 'begin') { $this->drv->begin(); }
            elseif ($what === 'commit') { $this->drv->commit(); }
            else { $this->drv->rollback(); }
        } catch (PDOException $e) {
            $this->note($e, $fn);
            return false;
        }
        return true;
    }

    /** php reports `00000` after a SUCCESSFUL operation; the pristine `''` /
     *  null state belongs only to a handle nothing has run on yet. */
    public function clearErr(): void
    {
        $this->errState = '00000';
        $this->errCode = 0;
        $this->errHasCode = false;
        $this->errMsg = '';
        $this->errHasMsg = false;
    }

    /** Record a driver failure, then let the error mode decide what happens. */
    public function note(PDOException $e, string $fn = 'PDO::errorInfo'): mixed
    {
        /*
         * A PDOException with no SQLSTATE did not come from a driver operation —
         * it is one of php's own hard refusals (a NUL byte in quote(), a bad
         * fetch mode). php lets those escape whatever the error mode is, and
         * re-wrapping one would print `SQLSTATE[]: General error`.
         */
        if ($e->sqlstate === '' && !$e->hasDriverMsg) { throw $e; }
        $this->errState = $e->sqlstate;
        $this->errCode = $e->driverCode;
        $this->errHasCode = $e->hasDriverCode;
        $this->errMsg = $e->driverMsg;
        $this->errHasMsg = $e->hasDriverMsg;
        // with emulated prepares (mysql) HY093 comes from php core's parameter parser, an implementation error: like IM001 it
        // warns even under ERRMODE_SILENT (measured). pdo_pgsql's execute()-time HY093 (too many params) follows the error mode, while
        // its prepare()-time `mixed named and positional` warns under SILENT too in php (known gap here, not reproducible without a server).
        $mode = $this->errmode === PDO::ERRMODE_SILENT && $e->sqlstate === 'HY093' && !$e->hasDriverCode
            && $this->drv !== null && $this->drv->name() === 'mysql' ? PDO::ERRMODE_WARNING : $this->errmode;
        __McPdo::raise($mode, $e->sqlstate, $e->driverCode, $e->hasDriverCode,
                       $e->driverMsg, $e->hasDriverMsg, $this->label !== '' ? $this->label : $fn);
        return false;
    }

    /** php's IM001: the driver was asked for something it does not have. php raises it as an implementation
     *  error, which warns even under ERRMODE_SILENT (measured). */
    private function unsupported(string $msg, string $fn): mixed
    {
        $this->errState = 'IM001';
        $this->errCode = 0;
        $this->errHasCode = false;
        $this->errMsg = '';
        $this->errHasMsg = false;
        __McPdo::raise($this->errmode === PDO::ERRMODE_SILENT ? PDO::ERRMODE_WARNING : $this->errmode, 'IM001', 0, false, $msg, true, $fn);
        return false;
    }

    /** ATTR_STATEMENT_CLASS lets a program get its own subclass back. */
    private function makeStatement(__McPdoDrvStmt $ds, string $sql): PDOStatement
    {
        $cls = $this->stmtClassName;
        if ($cls === 'PDOStatement' || $cls === '') {
            $st = new PDOStatement();
        } else {
            $st = \count($this->stmtClassArgs) > 0
                ? new $cls(...$this->stmtClassArgs) : new $cls();
        }
        $st->drv = $ds;
        $st->db = $this;
        $st->queryString = $sql;
        $st->fetchMode = $this->defaultFetch;
        return $st;
    }
}

// ── PDOStatement ────────────────────────────────────────────────────────────

#[\Manticore\Attr\Uncomparable]
class PDOStatement implements IteratorAggregate
{
    public string $queryString = '';

    public ?__McPdoDrvStmt $drv = null;
    public ?PDO $db = null;

    public int $fetchMode = 4;
    /** @var array<int,mixed> setFetchMode's trailing arguments */
    public array $fetchArgs = [];

    /** Typed for the same reason as PDO's — see the note there. */
    public string $errState = '';
    public int $errCode = 0;
    public bool $errHasCode = false;
    public string $errMsg = '';
    public bool $errHasMsg = false;

    /** True once execute() has run and a row may be pending. */
    public bool $executed = false;
    /** execute() leaves the first row pending; the first fetch consumes it. */
    public bool $pending = false;
    public bool $done = false;

    /** @var array<int,mixed> bindValue()/bindParam() values, by 1-based index */
    public array $bound = [];
    /** @var array<int,int> the PARAM_* type each bound value was given */
    public array $boundType = [];

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $this->bound = [];
            $this->boundType = [];
            foreach ($params as $k => $v) {
                /*
                 * php binds an execute() array as PARAM_STR throughout — that is
                 * why `execute([1])` stores sqlite TEXT '1', not an integer.
                 * A caller wanting a native type must say so with bindValue().
                 */
                if (\is_int($k)) {
                    if (!$this->bindValue($k + 1, $v, PDO::PARAM_STR)) { return false; }
                } else {
                    if (!$this->bindValue($k, $v, PDO::PARAM_STR)) { return false; }
                }
            }
        }
        try {
            foreach ($this->bound as $i => $v) {
                try {
                    $this->drv->bind($i, $v, $this->boundType[$i]);
                } catch (PDOException $e) {
                    // php registers a parameter before it validates it: the refused one is not kept, the others stay bound
                    unset($this->bound[$i], $this->boundType[$i]);
                    throw $e;
                }
            }
            $this->drv->execute();
        } catch (PDOException $e) {
            return $this->fail($e, 'PDOStatement::execute');
        }
        $this->clearErr();
        $this->executed = true;
        $this->pending = true;
        $this->done = false;
        return true;
    }

    public function bindValue(string|int $param, mixed $value, int $type = 2): bool
    {
        $idx = $this->resolveParam($param);
        if ($idx === 0) { return false; }
        $this->bound[$idx] = $value;
        $this->boundType[$idx] = $type;
        return true;
    }

    /**
     * ⚠ DIVERGENCE: php re-reads the caller's variable at execute() time; this
     * captures it at bind time.
     *
     * A stored by-reference binding needs a zval reference, and an array here
     * carries no is_ref bit — there is nothing to store. Bind-then-execute is
     * the overwhelmingly common shape and works identically; only a program that
     * mutates the variable BETWEEN bindParam() and execute() sees a difference.
     * Throwing instead would break every Doctrine-shaped caller outright, which
     * is why this one is documented rather than refused.
     */
    public function bindParam(string|int $param, mixed &$var, int $type = 2,
                              int $maxLength = 0, mixed $driverOptions = null): bool
    {
        return $this->bindValue($param, $var, $type);
    }

    /**
     * ⚠ REFUSED, for the same missing primitive — but here the write-back IS the
     * entire feature, so there is no half of it worth approximating.
     */
    public function bindColumn(string|int $column, mixed &$var, int $type = 2,
                               int $maxLength = 0, mixed $driverOptions = null): bool
    {
        throw new PDOException(
            'PDOStatement::bindColumn() is not implemented: it needs a stored '
            . 'by-reference binding, which has no representation here. '
            . 'Use fetch(PDO::FETCH_ASSOC) or fetch(PDO::FETCH_NUM) instead.', 0);
    }

    public function fetch(int $mode = 0, int $cursorOrientation = 0, int $cursorOffset = 0): mixed
    {
        /*
         * $cursorOrientation and $cursorOffset are accepted and IGNORED, which
         * is what php does here: a sqlite statement is a forward-only cursor,
         * and pdo_sqlite hands back the next row whatever orientation is asked
         * for (measured: ORI_PRIOR / FIRST / LAST / ABS / REL all behave as
         * ORI_NEXT). Throwing was stricter than the oracle.
         */
        $eff = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;
        if (!$this->advance()) { return false; }
        // The base mode is the low nibble; PDO's flag bits all start at 32.
        $base = $eff & 15;
        // The array modes keep their own `: array` return rather than widening
        // this method's `array|object|false` merge any further — the narrower
        // channel is what the element repr survives best.
        if ($this->isArrayMode($base)) {
            return $this->rowArray($base, $eff, $this->fetchArgs, -1);
        }
        return $this->buildRow($base, $eff, $this->fetchArgs);
    }

    public function fetchAll(int $mode = 0, mixed ...$args): mixed
    {
        if ($mode === PDO::FETCH_DEFAULT) {
            $mode = $this->fetchMode;
            if (\count($args) === 0) { $args = $this->fetchArgs; }
        }
        $base = $mode & 15;
        $group = ($mode & PDO::FETCH_GROUP) !== 0;
        $unique = ($mode & PDO::FETCH_UNIQUE) !== 0;

        if ($base === PDO::FETCH_FUNC) {
            if (\count($args) === 0) {
                throw new PDOException('PDO::FETCH_FUNC needs a callable', 0);
            }
            /** @var array<int,mixed> */
            $out = [];
            while ($this->advance()) {
                $n = $this->drv->columnCount();
                /** @var array<int,mixed> */
                $cols = [];
                for ($i = 0; $i < $n; $i++) { $cols[] = $this->value($i); }
                $out[] = __McPdo::callArgs($args[0], $cols);
            }
            return $out;
        }

        if ($group || $unique) { return $this->fetchAllKeyed($base, $mode, $args, $group); }

        if ($base === PDO::FETCH_KEY_PAIR) {
            // The only mode whose ROWS become the result's KEYS, so it cannot go
            // through the append loop below.
            if ($this->drv->columnCount() !== 2) {
                throw new PDOException(
                    'PDO::FETCH_KEY_PAIR needs a result set with exactly 2 columns', 0);
            }
            $out = [];
            while ($this->advance()) { $out[$this->value(0)] = $this->value(1); }
            return $out;
        }

        /** @var array<int,mixed> */
        $out = [];
        while ($this->advance()) { $out[] = $this->buildRow($base, $mode, $args); }
        return $out;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (!$this->advance()) { return false; }
        if ($column < 0 || $column >= $this->drv->columnCount()) {
            return $this->outOfRange();
        }
        return $this->value($column);
    }

    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): mixed
    {
        if (!$this->advance()) { return false; }
        return $this->makeObject($class === null ? 'stdClass' : $class,
                                 $constructorArgs, false, null);
    }

    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->fetchMode = $mode;
        $this->fetchArgs = $args;
        return true;
    }

    public function rowCount(): int
    {
        return $this->drv->rowCount();
    }

    /**
     * php samples the column count AT execute() and reports 0 before that, even
     * though the driver has known it since prepare(). Observable, so matched.
     */
    public function columnCount(): int
    {
        return $this->executed ? $this->drv->columnCount() : 0;
    }

    public function getColumnMeta(int $column): mixed
    {
        if ($column < 0 || $column >= $this->drv->columnCount()) { return false; }
        return $this->drv->columnMeta($column);
    }

    public function closeCursor(): bool
    {
        $this->drv->closeCursor();
        $this->pending = false;
        $this->done = true;
        return true;
    }

    /** sqlite has no second result set; php refuses rather than answering false. */
    public function nextRowset(): bool
    {
        $d = $this->drv;
        if ($d instanceof __McPdoDrvMulti) {
            try {
                if (!$d->nextRowset()) { return false; }
            } catch (PDOException $e) {
                return $this->fail($e, 'PDOStatement::nextRowset');
            }
            $this->pending = true;
            $this->done = false;
            return true;
        }
        $this->unsupported('driver does not support multiple rowsets', 'PDOStatement::nextRowset');
        return false;
    }

    public function errorCode(): mixed
    {
        return $this->errState === '' ? null : $this->errState;
    }

    public function errorInfo(): array
    {
        return __McPdo::triple($this->errState, $this->errCode, $this->errHasCode,
                               $this->errMsg, $this->errHasMsg);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        // php (measured): sqlite answers a plain false in every error mode; pgsql raises IM001
        if ($this->db !== null && $this->db->drv->name() === 'sqlite') { return false; }
        $this->unsupported('This driver doesn\'t support setting attributes', 'PDOStatement::setAttribute');
        return false;
    }

    public function getAttribute(int $name): mixed
    {
        return $this->unsupported('driver doesn\'t support getting that attribute', 'PDOStatement::getAttribute');
    }

    public function debugDumpParams(): void
    {
        echo 'SQL: [', \strlen($this->queryString), '] ', $this->queryString, "\n";
        echo 'Params:  ', \count($this->bound), "\n";
        foreach ($this->bound as $i => $v) {
            echo 'Key: Position #', $i - 1, ":\n";
            echo 'paramno=', $i - 1, "\nis_param=1\nparam_type=", $this->boundType[$i], "\n";
        }
    }

    /**
     * A CONCRETE Iterator class, never a Generator and never typed
     * `Traversable`: `foreach` picks its loop shape from the subject's static
     * type, and a Generator carries no class descriptor for the protocol calls
     * to land on. That combination is what once made an IteratorAggregate render
     * zero rows.
     */
    public function getIterator(): __McPdoStmtIter
    {
        // The CONCRETE class, not the `Iterator` interface php declares: a
        // covariant return is legal, and an interface-typed one leaves the
        // foreach protocol calls landing on an erased receiver.
        return new __McPdoStmtIter($this);
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function errmode(): int
    {
        return $this->db === null ? PDO::ERRMODE_EXCEPTION : $this->db->errmode;
    }

    private function clearErr(): void
    {
        $this->errState = '00000';
        $this->errCode = 0;
        $this->errHasCode = false;
        $this->errMsg = '';
        $this->errHasMsg = false;
    }

    /** A statement failure is also the CONNECTION's last error, as in php. */
    private function fail(PDOException $e, string $fn): bool
    {
        $this->errState = $e->sqlstate;
        $this->errCode = $e->driverCode;
        $this->errHasCode = $e->hasDriverCode;
        $this->errMsg = $e->driverMsg;
        $this->errHasMsg = $e->hasDriverMsg;
        if ($this->db !== null) { $this->db->note($e, $fn); return false; }
        __McPdo::raise(PDO::ERRMODE_EXCEPTION, $e->sqlstate, $e->driverCode,
                       $e->hasDriverCode, $e->driverMsg, $e->hasDriverMsg, $fn);
        return false;
    }

    private function unsupported(string $msg, string $fn): mixed
    {
        $this->errState = 'IM001';
        $this->errCode = 0;
        $this->errHasCode = false;
        $this->errMsg = '';
        $this->errHasMsg = false;
        __McPdo::raise($this->errmode() === PDO::ERRMODE_SILENT ? PDO::ERRMODE_WARNING : $this->errmode(), 'IM001', 0, false, $msg, true, $fn);
        return false;
    }

    /** sqlite's own code 25 for a column that is not in the result set. */
    private function outOfRange(): mixed
    {
        $this->errState = 'HY000';
        $this->errCode = 25;
        $this->errHasCode = true;
        $this->errMsg = 'column index out of range';
        $this->errHasMsg = true;
        __McPdo::raise($this->errmode(), 'HY000', 25, true,
                       'column index out of range', true);
        return false;
    }

    /**
     * Move to the row a fetch should read.
     *
     * execute() leaves the first row PENDING rather than consumed, so the first
     * fetch must not step past it — php's cursor contract, and the reason
     * rowCount() is meaningful before any fetch happens.
     */
    private function advance(): bool
    {
        if (!$this->executed || $this->done) { return false; }
        if ($this->pending) {
            $this->pending = false;
            if (!$this->drv->step()) { $this->done = true; return false; }
            return true;
        }
        if (!$this->drv->step()) { $this->done = true; return false; }
        return true;
    }

    /** One column, with ATTR_STRINGIFY_FETCHES and ATTR_ORACLE_NULLS applied. */
    private function value(int $i): mixed
    {
        $v = $this->drv->columnValue($i);
        $db = $this->db;
        if ($db === null) { return $v; }
        if ($v === null && $db->oracleNulls === PDO::NULL_TO_STRING) { return ''; }
        if ($v === '' && $db->oracleNulls === PDO::NULL_EMPTY_STRING) { return null; }
        // php stringifies a bool as its integer (false is "0", not "") and leaves a stream alone
        if ($db->stringify && $v !== null && !\is_resource($v)) {
            return \is_bool($v) ? ($v ? '1' : '0') : (string) $v;
        }
        return $v;
    }

    private function columnKey(int $i): string
    {
        $n = $this->drv->columnName($i);
        return $this->db === null ? $n : __McPdo::applyCase($n, $this->db->case);
    }

    /** @return array<int,mixed> the row under $base, with $skip's entries dropped */
    private function buildRow(int $base, int $flags, array $args, int $skip = -1): mixed
    {
        $n = $this->drv->columnCount();

        if ($base === PDO::FETCH_NUM) {
            /** @var array<int,mixed> */
            $r = [];
            for ($i = 0; $i < $n; $i++) {
                if ($i === $skip) { continue; }
                $r[$i] = $this->value($i);
            }
            return $r;
        }
        if ($base === PDO::FETCH_ASSOC) {
            /** @var array<string,mixed> */
            $r = [];
            for ($i = 0; $i < $n; $i++) {
                if ($i === $skip) { continue; }
                $r[$this->columnKey($i)] = $this->value($i);
            }
            return $r;
        }
        if ($base === PDO::FETCH_BOTH || $base === PDO::FETCH_DEFAULT) {
            return $this->rowBoth($n, $skip);
        }
        if ($base === PDO::FETCH_NAMED) {
            /*
             * ASSOC, except a repeated column name collects into a list rather
             * than the later value silently winning.
             */
            return $this->rowNamed($n, $skip);
        }
        if ($base === PDO::FETCH_COLUMN) {
            $col = $skip >= 0 ? $skip + 1 : (\count($args) > 0 ? (int) $args[0] : 0);
            if ($col >= $n) { return $this->outOfRange(); }
            return $this->value($col);
        }
        if ($base === PDO::FETCH_KEY_PAIR) {
            if ($n !== 2) {
                throw new PDOException(
                    'PDO::FETCH_KEY_PAIR needs a result set with exactly 2 columns', 0);
            }
            return [$this->value(0), $this->value(1)];
        }
        if ($base === PDO::FETCH_OBJ) {
            return $this->makeObject('stdClass', [], false, $skip);
        }
        if ($base === PDO::FETCH_CLASS) {
            $cls = \count($args) > 0 && $args[0] !== null ? (string) $args[0] : 'stdClass';
            $ctor = \count($args) > 1 && \is_array($args[1]) ? $args[1] : [];
            return $this->makeObject($cls, $ctor, ($flags & PDO::FETCH_PROPS_LATE) !== 0, $skip);
        }
        if ($base === PDO::FETCH_INTO) {
            if (\count($args) === 0 || !\is_object($args[0])) {
                throw new PDOException('PDO::FETCH_INTO needs an object', 0);
            }
            $o = $args[0];
            for ($i = 0; $i < $n; $i++) {
                if ($i === $skip) { continue; }
                $k = $this->columnKey($i);
                $o->$k = $this->value($i);
            }
            return $o;
        }
        if ($base === PDO::FETCH_LAZY) {
            $row = new PDORow();
            $row->queryString = $this->queryString;
            for ($i = 0; $i < $n; $i++) {
                if ($i === $skip) { continue; }
                $k = $this->columnKey($i);
                $row->$k = $this->value($i);
            }
            return $row;
        }
        if ($base === PDO::FETCH_BOUND) {
            throw new PDOException(
                'PDO::FETCH_BOUND is not implemented: it needs a stored '
                . 'by-reference binding, which has no representation here.', 0);
        }
        throw new PDOException('unsupported PDO fetch mode ' . $base, 0);
    }

    /** The fetch modes whose row IS an array — the ones {@see rowArray} serves. */
    private function isArrayMode(int $base): bool
    {
        return $base === PDO::FETCH_ASSOC || $base === PDO::FETCH_NUM
            || $base === PDO::FETCH_BOTH || $base === PDO::FETCH_NAMED
            || $base === PDO::FETCH_DEFAULT || $base === PDO::FETCH_KEY_PAIR;
    }

    /**
     * Every array-shaped fetch mode, behind ONE concrete `: array` return.
     *
     * The concrete `: array` return narrows the merge each row crosses — it is
     * good structure, but it does NOT fix the non-literal-mode crash. See the
     * ⚠ note in {@see fetch}.
     *
     * @return array<string,mixed>
     */
    private function rowArray(int $base, int $flags, array $args, int $skip): array
    {
        $n = $this->drv->columnCount();
        if ($base === PDO::FETCH_NUM) { return $this->rowNum($n, $skip); }
        if ($base === PDO::FETCH_ASSOC) { return $this->rowAssoc($n, $skip); }
        if ($base === PDO::FETCH_NAMED) { return $this->rowNamed($n, $skip); }
        if ($base === PDO::FETCH_KEY_PAIR) {
            if ($n !== 2) {
                throw new PDOException(
                    'PDO::FETCH_KEY_PAIR needs a result set with exactly 2 columns', 0);
            }
            return [$this->value(0), $this->value(1)];
        }
        return $this->rowBoth($n, $skip);
    }

    /** @return array<string,mixed> */
    private function rowNum(int $n, int $skip): array
    {
        /** @var array<string,mixed> */
        $r = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === $skip) { continue; }
            $r[$i] = $this->value($i);
        }
        return $r;
    }

    /** @return array<string,mixed> */
    private function rowAssoc(int $n, int $skip): array
    {
        /** @var array<string,mixed> */
        $r = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === $skip) { continue; }
            $r[$this->columnKey($i)] = $this->value($i);
        }
        return $r;
    }

    /**
     * FETCH_BOTH·s row: every column under BOTH its name and its index.
     *
     * ⚠ ITS OWN METHOD, and not an arm of buildRow(), for a reason that cost an
     * afternoon. A mixed-key array types UNKNOWN, and an UNKNOWN built inline
     * among arms of other shapes leaves buildRow()·s merged return carrying a
     * RAW buffer pointer — json_encode() then rendered the row as a denormal
     * double. Observable only when the mode is NOT a literal, because a literal
     * folds the if-chain and monomorphises the arm. Built behind its own
     * annotated `array` return, the same value crosses the erased channel intact.
     *
     * @return array<string,mixed>
     */
    private function rowBoth(int $n, int $skip): array
    {
        /** @var array<string,mixed> */
        $r = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === $skip) { continue; }
            $v = $this->value($i);
            $r[$this->columnKey($i)] = $v;
            $r[$i] = $v;
        }
        return $r;
    }

    /**
     * FETCH_NAMED·s row — ASSOC, except a repeated column name collects into a
     * list rather than the later value silently winning. Own method for the same
     * reason as {@see rowBoth}.
     *
     * @return array<string,mixed>
     */
    private function rowNamed(int $n, int $skip): array
    {
        /** @var array<string,mixed> */
        $r = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === $skip) { continue; }
            $k = $this->columnKey($i);
            $v = $this->value($i);
            if (!isset($r[$k])) { $r[$k] = $v; continue; }
            $cur = $r[$k];
            if (\is_array($cur)) { $cur[] = $v; $r[$k] = $cur; }
            else { $r[$k] = [$cur, $v]; }
        }
        return $r;
    }

    /**
     * FETCH_CLASS / FETCH_OBJ / fetchObject.
     *
     * php sets the properties BEFORE running the constructor unless
     * FETCH_PROPS_LATE says otherwise — observable, because a constructor
     * reading `$this->id` sees the fetched value in the default order and null
     * with PROPS_LATE.
     */
    private function makeObject(string $cls, array $ctorArgs, bool $propsLate, ?int $skip): mixed
    {
        $n = $this->drv->columnCount();
        $sk = $skip === null ? -1 : $skip;

        if ($propsLate) {
            $o = \count($ctorArgs) > 0 ? new $cls(...$ctorArgs) : new $cls();
            for ($i = 0; $i < $n; $i++) {
                if ($i === $sk) { continue; }
                $k = $this->columnKey($i);
                $o->$k = $this->value($i);
            }
            return $o;
        }

        $o = new $cls();
        for ($i = 0; $i < $n; $i++) {
            if ($i === $sk) { continue; }
            $k = $this->columnKey($i);
            $o->$k = $this->value($i);
        }
        if (\count($ctorArgs) > 0) { $o->__construct(...$ctorArgs); }
        elseif (\method_exists($o, '__construct')) { $o->__construct(); }
        return $o;
    }

    /**
     * FETCH_GROUP / FETCH_UNIQUE: column 0 becomes the key and drops out of the
     * row, which is why buildRow() takes a `skip`. FETCH_COLUMN under a grouping
     * flag reads column 1, php's rule.
     */
    private function fetchAllKeyed(int $base, int $flags, array $args, bool $group): mixed
    {
        $out = [];
        while ($this->advance()) {
            $key = $this->value(0);
            $row = $this->buildRow($base, $flags, $args, 0);
            if (!$group) { $out[$key] = $row; continue; }
            if (!isset($out[$key])) { $out[$key] = [$row]; continue; }
            $cur = $out[$key];
            $cur[] = $row;
            $out[$key] = $cur;
        }
        return $out;
    }

    private function resolveParam(string|int $param): int
    {
        if (\is_int($param)) { return $param; }
        $s = (string) $param;
        if ($s === '') { return 0; }
        /*
         * php accepts a placeholder with or without its sigil. The driver wants
         * the spelling the SQL used, so restore the `:` when it was dropped —
         * `@name` and `$name` are already sigil-carrying and pass through.
         */
        $c = $s[0];
        if ($c !== ':' && $c !== '@' && $c !== '$') { $s = ':' . $s; }
        $i = $this->drv->paramIndex($s);
        if ($i === 0 && \ctype_digit((string) $param)) { return (int) $param; }
        return $i;
    }
}

/**
 * The iterator behind `foreach ($stmt as $row)`.
 *
 * php numbers the keys 0,1,2… independently of the data, and its cursor is
 * forward-only: `rewind()` after the first row is an error there and a no-op
 * here rather than a silent re-run of the query.
 */
final class __McPdoStmtIter implements Iterator
{
    public PDOStatement $st;
    public mixed $row = false;
    public int $i = -1;
    public bool $started = false;

    public function __construct(PDOStatement $st)
    {
        $this->st = $st;
    }

    public function rewind(): void
    {
        if ($this->started) { return; }
        $this->started = true;
        $this->i = 0;
        $this->row = $this->st->fetch();
    }

    public function valid(): bool
    {
        return $this->row !== false;
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
        $this->row = $this->st->fetch();
    }
}

/**
 * What PDO::FETCH_LAZY hands back: a bag of columns plus the query text.
 *
 * ⚠ #[AllowDynamicProperties] is load-bearing, not decoration: every column is
 * written as a DYNAMIC property, and without a bag those writes had nowhere to
 * land — the row read back empty.
 */
#[\AllowDynamicProperties]
class PDORow
{
    public string $queryString = '';
}
