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
    public function quote(string $s, int $type): string;

    /** @return array<int,mixed> [SQLSTATE, code, message] */
    public function errorInfo(): array;

    /** False when the driver does not know the attribute at all. */
    public function setAttr(int $attr, mixed $value): bool;
    public function getAttr(int $attr): mixed;

    public function close(): void;
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
    /** php's `pdo_sqlstate_state_to_description` table, trimmed to what we emit. */
    public static function sqlstateText(string $state): string
    {
        if ($state === '00000') { return 'No error'; }
        if ($state === '23000') { return 'Integrity constraint violation'; }
        if ($state === '22007') { return 'Invalid datetime format'; }
        if ($state === '26000') { return 'Invalid SQL statement name'; }
        if ($state === '28000') { return 'Invalid authorization specification'; }
        if ($state === '42000') { return 'Syntax error or access violation'; }
        if ($state === 'IM001') { return 'Driver does not support this function'; }
        return 'General error';
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
                                 string $msg, bool $hasMsg): void
    {
        if ($errmode === PDO::ERRMODE_EXCEPTION) {
            throw self::fail($state, $code, $hasCode, $msg, $hasMsg);
        }
        if ($errmode === PDO::ERRMODE_WARNING) {
            \trigger_error('PDO::errorInfo(): '
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
    public const SQLITE_ATTR_OPEN_FLAGS = 1000;
    public const SQLITE_ATTR_READONLY_STATEMENT = 1001;
    public const SQLITE_ATTR_EXTENDED_RESULT_CODES = 1002;
    public const SQLITE_OPEN_READONLY = 1;
    public const SQLITE_OPEN_READWRITE = 2;
    public const SQLITE_OPEN_CREATE = 4;
    public const SQLITE_DETERMINISTIC = 2048;

    public ?__McPdoDrv $drv = null;

    public int $errmode = 2;
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
         * than a runtime surprise. Adding mysql means adding one arm here and
         * splitting the prelude gate in Main.php the same way.
         */
        if ($scheme === 'sqlite') {
            $this->drv = __mc_pdo_sqlite_open($rest, $options === null ? [] : $options);
        } else {
            // php reports an unknown scheme with no SQLSTATE and no driver code,
            // and its message carries no SQLSTATE prefix either.
            $e = new PDOException('could not find driver', 0);
            $e->errorInfo = __McPdo::triple('', 0, false, '', false);
            throw $e;
        }

        if ($options !== null) {
            foreach ($options as $k => $v) {
                if (\is_int($k)) { $this->setAttribute($k, $v); }
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
        return ['sqlite'];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            $ds = $this->drv->prepare($query);
        } catch (PDOException $e) {
            $this->note($e);
            return false;
        }
        $this->clearErr();
        return $this->makeStatement($ds, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $st = $this->prepare($query);
        if ($st === false) { return false; }
        if ($fetchMode !== null) { $st->setFetchMode($fetchMode, ...$fetchModeArgs); }
        if ($st->execute() === false) { return false; }
        return $st;
    }

    public function exec(string $statement): mixed
    {
        try {
            $n = $this->drv->exec($statement);
        } catch (PDOException $e) {
            return $this->note($e);
        }
        $this->clearErr();
        return $n;
    }

    public function beginTransaction(): bool
    {
        if ($this->drv->inTransaction()) {
            throw new PDOException('There is already an active transaction', 0);
        }
        return $this->run('begin');
    }

    public function commit(): bool
    {
        if (!$this->drv->inTransaction()) {
            throw new PDOException('There is no active transaction', 0);
        }
        return $this->run('commit');
    }

    public function rollBack(): bool
    {
        if (!$this->drv->inTransaction()) {
            throw new PDOException('There is no active transaction', 0);
        }
        return $this->run('rollback');
    }

    public function inTransaction(): bool
    {
        return $this->drv->inTransaction();
    }

    public function lastInsertId(?string $name = null): mixed
    {
        return $this->drv->lastInsertId($name);
    }

    public function quote(string $string, int $type = 2): mixed
    {
        try {
            return $this->drv->quote($string, $type);
        } catch (PDOException $e) {
            return $this->note($e);
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
        if ($attribute === PDO::ATTR_ERRMODE) { $this->errmode = (int) $value; return true; }
        if ($attribute === PDO::ATTR_CASE) { $this->case = (int) $value; return true; }
        if ($attribute === PDO::ATTR_ORACLE_NULLS) { $this->oracleNulls = (int) $value; return true; }
        if ($attribute === PDO::ATTR_STRINGIFY_FETCHES) { $this->stringify = (bool) $value; return true; }
        if ($attribute === PDO::ATTR_DEFAULT_FETCH_MODE) { $this->defaultFetch = (int) $value; return true; }
        // Accepted and ignored: one process, no connection pool to keep.
        if ($attribute === PDO::ATTR_PERSISTENT) { $this->persistent = (bool) $value; return true; }
        // Consumed by the driver's open(); accepted so it can ride the options
        // array without then being reported as unsupported.
        if ($attribute === PDO::SQLITE_ATTR_OPEN_FLAGS) { return true; }
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
        if ($this->drv->setAttr($attribute, $value)) { return true; }
        return $this->unsupported('driver does not support that attribute');
    }

    public function getAttribute(int $attribute): mixed
    {
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
        $v = $this->drv->getAttr($attribute);
        if ($v !== null) { return $v; }
        return $this->unsupported('driver does not support that attribute');
    }

    /** Shared body of begin/commit/rollback, so all three report identically. */
    private function run(string $what): bool
    {
        try {
            if ($what === 'begin') { $this->drv->begin(); }
            elseif ($what === 'commit') { $this->drv->commit(); }
            else { $this->drv->rollback(); }
        } catch (PDOException $e) {
            $this->note($e);
            return false;
        }
        $this->clearErr();
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
    public function note(PDOException $e): mixed
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
        __McPdo::raise($this->errmode, $e->sqlstate, $e->driverCode, $e->hasDriverCode,
                       $e->driverMsg, $e->hasDriverMsg);
        return false;
    }

    /** php's IM001: the driver was asked for something it does not have. */
    private function unsupported(string $msg): mixed
    {
        $this->errState = 'IM001';
        $this->errCode = 0;
        $this->errHasCode = false;
        $this->errMsg = $msg;
        $this->errHasMsg = true;
        __McPdo::raise($this->errmode, 'IM001', 0, false, $msg, true);
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
                $this->drv->bind($i, $v, $this->boundType[$i]);
            }
            $this->drv->execute();
        } catch (PDOException $e) {
            return $this->fail($e);
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
        $eff = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;
        if ($cursorOrientation !== PDO::FETCH_ORI_NEXT) {
            throw new PDOException(
                'PDO::FETCH_ORI_NEXT is the only cursor orientation implemented', 0);
        }
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
        $this->unsupported('driver does not support multiple rowsets');
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
        $this->unsupported('This driver doesn\'t support setting attributes');
        return false;
    }

    public function getAttribute(int $name): mixed
    {
        return $this->unsupported('This driver doesn\'t support getting attributes');
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
    private function fail(PDOException $e): bool
    {
        $this->errState = $e->sqlstate;
        $this->errCode = $e->driverCode;
        $this->errHasCode = $e->hasDriverCode;
        $this->errMsg = $e->driverMsg;
        $this->errHasMsg = $e->hasDriverMsg;
        if ($this->db !== null) { $this->db->note($e); return false; }
        __McPdo::raise(PDO::ERRMODE_EXCEPTION, $e->sqlstate, $e->driverCode,
                       $e->hasDriverCode, $e->driverMsg, $e->hasDriverMsg);
        return false;
    }

    private function unsupported(string $msg): mixed
    {
        $this->errState = 'IM001';
        $this->errCode = 0;
        $this->errHasCode = false;
        $this->errMsg = $msg;
        $this->errHasMsg = true;
        __McPdo::raise($this->errmode(), 'IM001', 0, false, $msg, true);
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
        if ($db->stringify && $v !== null) { return (string) $v; }
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
