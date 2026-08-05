<?php

/**
 * pdo_sqlite — the SQLite driver behind PDO, bound to libsqlite3 through FFI.
 *
 * WHY FFI AND NOT A SOCKET: SQLite is not a server. There is no wire protocol
 * and nothing to pack/unpack — `sqlite3_step` is a `pread` on a local file. A
 * mysql or pgsql driver is the opposite shape (socket + pack/unpack, no FFI at
 * all), which is exactly why PDO talks to the {@see __McPdoDrv} seam in pdo.php
 * and never to a database directly.
 *
 * WHY LIBSQLITE3 IS A GOOD FFI TARGET: the C API is all-function over opaque
 * `sqlite3*` / `sqlite3_stmt*` handles. Nothing here dereferences a sqlite
 * struct, so `\Ffi\Ptr`'s missing `read*` family costs nothing. And unlike
 * libcurl, this driver needs NO callbacks at all — `sqlite3_exec`'s row callback
 * is avoidable by driving prepare/step ourselves, so there are no trampolines
 * and no "must not throw out of a C frame" hazard on this path.
 *
 * NO PLACEHOLDER REWRITING. sqlite binds `?`, `:name`, `@name` and `$name`
 * natively, and `sqlite3_bind_parameter_index()` maps a name to its index
 * exactly — including the quoting and comment rules, which it already had to
 * implement to parse the statement. Writing our own SQL scanner would only be a
 * second, worse implementation of that; an emulated-prepare driver would need
 * one, and that is the driver's business, not the facade's.
 *
 * Attributes are fully qualified: the global-namespace prelude is concatenated
 * into ONE blob, so there is nowhere to put a `use`.
 */

// ── FFI: libsqlite3 ─────────────────────────────────────────────────────────
//
// `#[Library('sqlite3')]` is the whole link story: the emitter collects the
// library off every wrapper it emits and Main's generic_link_flags() resolves it
// through `pkg-config --libs sqlite3`. A binary that never mentions PDO never
// emits a wrapper and never links it.
//
// Every C `int` return carries a FUNCTION-level #[CType('int')]. Without it a
// returned -1 reads back as 4294967295 — sqlite returns small positive result
// codes, but `sqlite3_bind_parameter_index` answering 0 and `sqlite3_column_bytes`
// answering a length both go through the same path, and the omission is the kind
// that only shows up on one platform.

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_open_v2'), \Ffi\CType('int')]
function __mc_pdosq_open_v2(string $file, \Ffi\Ptr $ppDb, #[\Ffi\CType('int')] int $flags,
                            \Ffi\Ptr $vfs): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_close_v2'), \Ffi\CType('int')]
function __mc_pdosq_close_v2(\Ffi\Ptr $db): int {}

/**
 * `int sqlite3_prepare_v2(sqlite3*, const char *zSql, int nByte,
 *                         sqlite3_stmt **ppStmt, const char **pzTail)`
 *
 * Both out-parameters ride ONE calloc'd 16-byte block: ppStmt at +0, pzTail at
 * +8. `pzTail` is what makes multi-statement `PDO::exec()` possible — it points
 * back INTO the caller's own SQL bytes, so the offset of the next statement is
 * `tail - str_bytes($sql)`.
 */
#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_prepare_v2'), \Ffi\CType('int')]
function __mc_pdosq_prepare_v2(\Ffi\Ptr $db, string $sql, #[\Ffi\CType('int')] int $n,
                               \Ffi\Ptr $ppStmt, \Ffi\Ptr $pzTail): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_step'), \Ffi\CType('int')]
function __mc_pdosq_step(\Ffi\Ptr $st): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_reset'), \Ffi\CType('int')]
function __mc_pdosq_reset(\Ffi\Ptr $st): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_clear_bindings'), \Ffi\CType('int')]
function __mc_pdosq_clear_bindings(\Ffi\Ptr $st): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_finalize'), \Ffi\CType('int')]
function __mc_pdosq_finalize(\Ffi\Ptr $st): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_stmt_readonly'), \Ffi\CType('int')]
function __mc_pdosq_stmt_readonly(\Ffi\Ptr $st): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_bind_parameter_count'), \Ffi\CType('int')]
function __mc_pdosq_bind_parameter_count(\Ffi\Ptr $st): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_bind_parameter_index'), \Ffi\CType('int')]
function __mc_pdosq_bind_parameter_index(\Ffi\Ptr $st, string $name): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_bind_null'), \Ffi\CType('int')]
function __mc_pdosq_bind_null(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_bind_int64'), \Ffi\CType('int')]
function __mc_pdosq_bind_int64(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i,
                               #[\Ffi\CType('longlong')] int $v): int {}

/**
 * A genuine C `double` argument.
 *
 * The "no float across FFI" rule is about a PHP function used as a C CALLBACK,
 * where every argument would arrive in a GP register. An FFI BINDING declares
 * the real prototype, so the value rides an FP register the way the ABI says.
 */
#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_bind_double'), \Ffi\CType('int')]
function __mc_pdosq_bind_double(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i,
                                #[\Ffi\CType('double')] float $v): int {}

/**
 * The destructor argument is `SQLITE_TRANSIENT` ((void*)-1) at every call site:
 * sqlite copies the bytes, so a PHP string never has to outlive the bind. It is
 * declared `ptr` because that is what the C prototype says — an integer token on
 * an address is exactly the sign-extension bug that rule exists to prevent.
 */
#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_bind_text'), \Ffi\CType('int')]
function __mc_pdosq_bind_text(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i, string $v,
                              #[\Ffi\CType('int')] int $n,
                              #[\Ffi\CType('ptr')] int $dtor): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_bind_blob'), \Ffi\CType('int')]
function __mc_pdosq_bind_blob(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i, \Ffi\Ptr $v,
                              #[\Ffi\CType('int')] int $n,
                              #[\Ffi\CType('ptr')] int $dtor): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_column_count'), \Ffi\CType('int')]
function __mc_pdosq_column_count(\Ffi\Ptr $st): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_column_type'), \Ffi\CType('int')]
function __mc_pdosq_column_type(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_column_name')]
function __mc_pdosq_column_name(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i): \Ffi\Ptr {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_column_decltype')]
function __mc_pdosq_column_decltype(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i): \Ffi\Ptr {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_column_int64'), \Ffi\CType('longlong')]
function __mc_pdosq_column_int64(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_column_double'), \Ffi\CType('double')]
function __mc_pdosq_column_double(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i): float {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_column_text')]
function __mc_pdosq_column_text(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i): \Ffi\Ptr {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_column_blob')]
function __mc_pdosq_column_blob(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i): \Ffi\Ptr {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_column_bytes'), \Ffi\CType('int')]
function __mc_pdosq_column_bytes(\Ffi\Ptr $st, #[\Ffi\CType('int')] int $i): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_changes'), \Ffi\CType('int')]
function __mc_pdosq_changes(\Ffi\Ptr $db): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_last_insert_rowid'), \Ffi\CType('longlong')]
function __mc_pdosq_last_insert_rowid(\Ffi\Ptr $db): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_errcode'), \Ffi\CType('int')]
function __mc_pdosq_errcode(\Ffi\Ptr $db): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_extended_errcode'), \Ffi\CType('int')]
function __mc_pdosq_extended_errcode(\Ffi\Ptr $db): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_errmsg')]
function __mc_pdosq_errmsg(\Ffi\Ptr $db): \Ffi\Ptr {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_get_autocommit'), \Ffi\CType('int')]
function __mc_pdosq_get_autocommit(\Ffi\Ptr $db): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_extended_result_codes'), \Ffi\CType('int')]
function __mc_pdosq_extended_result_codes(\Ffi\Ptr $db, #[\Ffi\CType('int')] int $on): int {}

#[\Ffi\Library('sqlite3'), \Ffi\Symbol('sqlite3_libversion')]
function __mc_pdosq_libversion(): \Ffi\Ptr {}

// ── FFI: libc ───────────────────────────────────────────────────────────────
//
// The PRELUDE MUST NOT DEPEND ON THE STDLIB — it is compiled into every module,
// including ones built with no stdlib beside them. So the libc entries this file
// needs are declared here with `__mc_pdosq_` names. The SIGNATURES must match
// src/Runtime/Libc.php exactly: every binding of one C symbol has to agree or
// the emitter rejects the module.

#[\Ffi\Library('c'), \Ffi\Symbol('calloc'), \Ffi\Give]
function __mc_pdosq_calloc(#[\Ffi\CType('size_t')] int $n,
                           #[\Ffi\CType('size_t')] int $sz): \Ffi\Ptr {}

#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function __mc_pdosq_free(#[\Ffi\Take] \Ffi\Ptr $p): void {}

// ── Result codes ────────────────────────────────────────────────────────────

/**
 * Shared driver state and the constants the two classes below agree on.
 *
 * The id counter and the backoff seam are statics because they are process-wide,
 * not per-handle — and because {@see __McPdoSq::backoff} has to work identically
 * whether it is reached from a connection or from a statement.
 */
final class __McPdoSq
{
    public const OK = 0;
    public const ERROR = 1;
    public const BUSY = 5;
    public const LOCKED = 6;
    public const CONSTRAINT = 19;
    public const MISMATCH = 20;
    public const AUTH = 23;
    public const NOTADB = 26;
    public const ROW = 100;
    public const DONE = 101;

    public const TYPE_INTEGER = 1;
    public const TYPE_FLOAT = 2;
    public const TYPE_TEXT = 3;
    public const TYPE_BLOB = 4;
    public const TYPE_NULL = 5;

    public const OPEN_READONLY = 1;
    public const OPEN_READWRITE = 2;
    public const OPEN_CREATE = 4;

    /** `SQLITE_TRANSIENT` — tell sqlite to COPY the bytes we hand it. */
    public const TRANSIENT = -1;

    /** Never 0: 0 is the id an uninitialised handle would carry. */
    public static int $nextId = 1;

    /** sqlite's result code → php's SQLSTATE, matching pdo_sqlite's own table. */
    public static function sqlstate(int $rc): string
    {
        if ($rc === self::OK) { return '00000'; }
        if ($rc === self::CONSTRAINT) { return '23000'; }
        if ($rc === self::MISMATCH) { return '22007'; }
        if ($rc === self::AUTH) { return '28000'; }
        if ($rc === self::NOTADB) { return '26000'; }
        return 'HY000';
    }

    /**
     * The exception for whatever the connection last failed at.
     *
     * Returns a PDOException rather than a triple: the triple would have to be a
     * heterogeneous array, and storing one erases its element channel — the
     * facade reads the exception's TYPED fields instead.
     */
    public static function failFrom(int $addr): PDOException
    {
        $db = \int_to_ptr($addr);
        $rc = \__mc_pdosq_errcode($db);
        $msg = \cstr_to_str(\__mc_pdosq_errmsg($db));
        return __McPdo::fail(self::sqlstate($rc), $rc, true, $msg, true);
    }

    /**
     * Wait out a SQLITE_BUSY, cooperatively when a scheduler is running.
     *
     * ⚠ NEVER `sqlite3_busy_timeout`. That sleeps inside a C frame, which stalls
     * the whole event loop — netpoller included — so one contended database
     * would freeze every unrelated fiber in an `Http\Server` process.
     *
     * The hook is `\Runtime\AsyncHook` and deliberately NOT `Async\`: async.php
     * is demand-gated on a program MENTIONING `Async\`, so naming it here would
     * drag the entire scheduler into every synchronous PDO program. AsyncHook
     * lives in src/ and is always present; with no scheduler installed
     * `active()` is one null check and this falls through to usleep().
     *
     * @return bool false once the deadline has passed
     */
    public static function backoff(int $attempt, float $deadline): bool
    {
        if (\microtime(true) >= $deadline) { return false; }
        $delay = 0.001 * (float) $attempt;
        if ($delay > 0.05) { $delay = 0.05; }
        if (\Runtime\AsyncHook::active()) {
            $sleeper = \Runtime\AsyncHook::sleeper();
            $sleeper($delay);
        } else {
            \usleep((int) ($delay * 1000000.0));
        }
        return true;
    }
}

// ── The connection ──────────────────────────────────────────────────────────

final class __McPdoSqliteDrv implements __McPdoDrv
{
    /**
     * The `sqlite3*` as a raw int ADDRESS. Not an `\Ffi\Ptr` property: a Ptr is
     * a foreign address deliberately excluded from refcounting, and holding one
     * in a property drags those exclusions into every rc path that touches this
     * object. Converted with int_to_ptr at each point of use.
     */
    public int $addr = 0;
    public int $id = 0;
    public bool $closed = false;

    /** How long a SQLITE_BUSY may be waited out, in seconds. php's default. */
    public float $timeout = 30.0;

    public function __construct(int $addr)
    {
        $this->addr = $addr;
        $this->id = __McPdoSq::$nextId;
        __McPdoSq::$nextId = __McPdoSq::$nextId + 1;
    }

    public function name(): string { return 'sqlite'; }

    public function prepare(string $sql): __McPdoDrvStmt
    {
        $r = $this->prepareOne($sql, 0);
        $st = $r[0];
        if ($st === 0) { throw $this->failure(); }
        return new __McPdoSqliteStmt($this, $st, $sql);
    }

    /**
     * php runs EVERY statement in the string and answers the row count of the
     * last one, which is why this loops over `pzTail` instead of preparing once.
     */
    public function exec(string $sql): int
    {
        $off = 0;
        $len = \strlen($sql);
        $ran = false;
        while ($off < $len) {
            $rest = \substr($sql, $off);
            if (\trim($rest) === '') { break; }
            $r = $this->prepareOne($sql, $off);
            $st = $r[0];
            $next = $r[1];
            if ($st === 0) { throw $this->failure(); }
            $stp = \int_to_ptr($st);
            $rc = $this->drive($stp);
            \__mc_pdosq_finalize($stp);
            if ($rc !== __McPdoSq::DONE && $rc !== __McPdoSq::ROW) {
                throw $this->failure();
            }
            $ran = true;
            if ($next <= $off) { break; }
            $off = $next;
        }
        if (!$ran) { return 0; }
                return \__mc_pdosq_changes(\int_to_ptr($this->addr));
    }

    public function begin(): void { $this->simple('BEGIN'); }
    public function commit(): void { $this->simple('COMMIT'); }
    public function rollback(): void { $this->simple('ROLLBACK'); }

    public function inTransaction(): bool
    {
        return \__mc_pdosq_get_autocommit(\int_to_ptr($this->addr)) === 0;
    }

    public function lastInsertId(?string $name): string
    {
        return (string) \__mc_pdosq_last_insert_rowid(\int_to_ptr($this->addr));
    }

    /**
     * sqlite's own quoting rule: double every `'`, wrap the lot.
     *
     * php REFUSES a NUL rather than truncating, because the quoted text is
     * spliced into SQL that sqlite parses as a C string — the tail would vanish
     * silently. PDO::PARAM_LOB is quoted as text here exactly as php's sqlite
     * driver does; only a bound parameter is a real blob.
     */
    public function quote(string $s, int $type): string
    {
        if (\strpos($s, "\x00") !== false) {
            throw new PDOException('SQLite PDO::quote does not support null bytes', 0);
        }
        return "'" . \str_replace("'", "''", $s) . "'";
    }

    public function errorInfo(): array { return __McPdo::triple('', 0, false, '', false); }

    public function setAttr(int $attr, mixed $value): bool
    {
        if ($attr === PDO::ATTR_TIMEOUT) {
            $this->timeout = (float) $value;
            return true;
        }
        if ($attr === PDO::SQLITE_ATTR_EXTENDED_RESULT_CODES) {
            \__mc_pdosq_extended_result_codes(\int_to_ptr($this->addr), $value ? 1 : 0);
            return true;
        }
        // Already consumed by the open: accepted so that passing it in the
        // constructor's options array does not then report IM001.
        if ($attr === PDO::SQLITE_ATTR_OPEN_FLAGS) { return true; }
        return false;
    }

    public function getAttr(int $attr): mixed
    {
        if ($attr === PDO::ATTR_SERVER_VERSION || $attr === PDO::ATTR_CLIENT_VERSION) {
            return \cstr_to_str(\__mc_pdosq_libversion());
        }
        // ⚠ null means "not mine", so the facade can report IM001 the way php
        // does — including for ATTR_TIMEOUT, which php's sqlite driver sets but
        // refuses to read back.
        return null;
    }

    public function close(): void
    {
        if ($this->closed || $this->addr === 0) { return; }
        \__mc_pdosq_close_v2(\int_to_ptr($this->addr));
        $this->closed = true;
        $this->addr = 0;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return array<int,mixed> [stmt address (0 on failure), next offset]
     *
     * ⚠ `pzTail` points INTO the buffer sqlite was handed, so the offset is
     * relative to THAT buffer — which is why the substring is taken once into a
     * local and its address read from the same value. Two `substr()` calls would
     * be two different temporaries and the arithmetic would be nonsense.
     */
    public function prepareOne(string $sql, int $off): array
    {
        $chunk = \substr($sql, $off);
        $base = \str_bytes($chunk);
        $out = \__mc_pdosq_calloc(16, 1);
        $rc = \__mc_pdosq_prepare_v2(
            \int_to_ptr($this->addr), $chunk, \strlen($chunk),
            $out, \ptr_offset($out, 8));
        $st = \peek_i64($out, 0);
        $tail = \peek_i64($out, 8);
        \__mc_pdosq_free($out);
        if ($rc !== __McPdoSq::OK) { return [0, $off]; }
        $next = \strlen($sql);
        if ($tail !== 0 && $tail >= $base) { $next = $off + ($tail - $base); }
        if ($next < $off || $next > \strlen($sql)) { $next = \strlen($sql); }
        return [$st, $next];
    }

    /** Step a statement to completion, waiting out a BUSY cooperatively. */
    public function drive(\Ffi\Ptr $st): int
    {
        $attempt = 0;
        $deadline = \microtime(true) + $this->timeout;
        while (true) {
            $rc = \__mc_pdosq_step($st);
            if ($rc === __McPdoSq::ROW) { continue; }
            if ($rc !== __McPdoSq::BUSY && $rc !== __McPdoSq::LOCKED) { return $rc; }
            $attempt = $attempt + 1;
            if (!__McPdoSq::backoff($attempt, $deadline)) { return $rc; }
        }
    }

    /** The exception for this connection's last failure. */
    public function failure(): PDOException
    {
        return __McPdoSq::failFrom($this->addr);
    }

    private function simple(string $sql): void
    {
        $r = $this->prepareOne($sql, 0);
        $st = $r[0];
        if ($st === 0) { throw $this->failure(); }
        $stp = \int_to_ptr($st);
        $rc = $this->drive($stp);
        \__mc_pdosq_finalize($stp);
        if ($rc !== __McPdoSq::DONE) { throw $this->failure(); }
            }
}

// ── The statement ───────────────────────────────────────────────────────────

final class __McPdoSqliteStmt implements __McPdoDrvStmt
{
    public ?__McPdoSqliteDrv $db = null;
    public int $addr = 0;
    public string $sql = '';
    public bool $closed = false;

    /** True when execute() landed on a row that no step() has handed out yet. */
    public bool $first = false;
    public bool $exhausted = false;

    /** @var array<int,mixed> index => value, applied at execute() */
    public array $pend = [];
    /** @var array<int,int> index => PARAM_* */
    public array $pendType = [];

    public int $changes = 0;
    public bool $readonly = true;

    public function __construct(__McPdoSqliteDrv $db, int $addr, string $sql)
    {
        $this->db = $db;
        $this->addr = $addr;
        $this->sql = $sql;
        $this->readonly = \__mc_pdosq_stmt_readonly(\int_to_ptr($addr)) !== 0;
    }

    public function paramCount(): int
    {
        return \__mc_pdosq_bind_parameter_count(\int_to_ptr($this->addr));
    }

    public function paramIndex(string $name): int
    {
        return \__mc_pdosq_bind_parameter_index(\int_to_ptr($this->addr), $name);
    }

    public function bind(int $idx, mixed $value, int $type): void
    {
        $this->pend[$idx] = $value;
        $this->pendType[$idx] = $type;
    }

    /**
     * Reset, re-apply every binding, then step ONCE so the first row is pending.
     *
     * Bindings are applied here rather than in bind() because a statement that
     * is mid-result-set must be reset before it will accept one, and a facade
     * that re-executes with new values would otherwise have to know that.
     */
    public function execute(): void
    {
        $st = \int_to_ptr($this->addr);
        \__mc_pdosq_reset($st);
        \__mc_pdosq_clear_bindings($st);

        $n = $this->paramCount();
        foreach ($this->pend as $i => $v) {
            if ($i < 1 || $i > $n) {
                // php reports sqlite's own out-of-range code here, not its own.
                throw __McPdo::fail('HY000', 25, true, 'column index out of range', true);
            }
            $this->apply($st, $i, $v, $this->pendType[$i]);
        }

        $rc = $this->stepOnce($st);
        if ($rc !== __McPdoSq::ROW && $rc !== __McPdoSq::DONE) {
            \__mc_pdosq_reset($st);
            throw $this->db->failure();
        }
        /*
         * php's rowCount() is the CONNECTION's change counter sampled right
         * after the statement ran, and it reads 0 for a SELECT. sqlite3_changes
         * is connection-wide and sticky, so sampling it later would report an
         * unrelated statement's work.
         */
        $this->changes = $this->readonly
            ? 0 : \__mc_pdosq_changes(\int_to_ptr($this->db->addr));
                $this->first = $rc === __McPdoSq::ROW;
        $this->exhausted = $rc === __McPdoSq::DONE;
    }

    public function step(): bool
    {
        if ($this->exhausted) { return false; }
        if ($this->first) { $this->first = false; return true; }
        $rc = $this->stepOnce(\int_to_ptr($this->addr));
        if ($rc === __McPdoSq::ROW) { return true; }
        $this->exhausted = true;
        if ($rc !== __McPdoSq::DONE) {
            throw $this->db->failure();
        }
        return false;
    }

    public function columnCount(): int
    {
        return \__mc_pdosq_column_count(\int_to_ptr($this->addr));
    }

    public function columnName(int $i): string
    {
        $p = \__mc_pdosq_column_name(\int_to_ptr($this->addr), $i);
        return \ptr_to_int($p) === 0 ? '' : \cstr_to_str($p);
    }

    /**
     * php reports the type of the value in the CURRENT row, not the declared
     * column type — the two differ in sqlite, which is dynamically typed per
     * value.
     *
     * ⚠ `table` is absent. php fills it from `sqlite3_column_table_name()`,
     * which only exists when libsqlite3 was built with
     * SQLITE_ENABLE_COLUMN_METADATA; binding a symbol that may not be there
     * would trade a missing key for a link failure.
     *
     * @return array<string,mixed>
     */
    public function columnMeta(int $i): array
    {
        $st = \int_to_ptr($this->addr);
        $t = \__mc_pdosq_column_type($st, $i);

        $native = 'null';
        $pdoType = PDO::PARAM_NULL;
        if ($t === __McPdoSq::TYPE_INTEGER) { $native = 'integer'; $pdoType = PDO::PARAM_INT; }
        elseif ($t === __McPdoSq::TYPE_FLOAT) { $native = 'double'; $pdoType = PDO::PARAM_STR; }
        elseif ($t === __McPdoSq::TYPE_TEXT) { $native = 'string'; $pdoType = PDO::PARAM_STR; }
        elseif ($t === __McPdoSq::TYPE_BLOB) { $native = 'blob'; $pdoType = PDO::PARAM_STR; }

        /** @var array<string,mixed> */
        $meta = ['native_type' => $native, 'pdo_type' => $pdoType];
        $d = \__mc_pdosq_column_decltype($st, $i);
        if (\ptr_to_int($d) !== 0) { $meta['sqlite:decl_type'] = \cstr_to_str($d); }
        $meta['flags'] = [];
        $meta['name'] = $this->columnName($i);
        $meta['len'] = -1;
        $meta['precision'] = 0;
        return $meta;
    }

    /**
     * ⚠ ORDER IS LOAD-BEARING: sqlite3_column_bytes() reports the length of the
     * representation the LAST column_*() call produced, so the text/blob pointer
     * must be taken FIRST. Reading the length first can answer for a different
     * encoding entirely.
     *
     * The bytes are copied out immediately: sqlite's buffer carries no rc header
     * and is invalidated by the next step().
     */
    public function columnValue(int $i): mixed
    {
        $st = \int_to_ptr($this->addr);
        $t = \__mc_pdosq_column_type($st, $i);
        if ($t === __McPdoSq::TYPE_NULL) { return null; }
        if ($t === __McPdoSq::TYPE_INTEGER) { return \__mc_pdosq_column_int64($st, $i); }
        if ($t === __McPdoSq::TYPE_FLOAT) { return \__mc_pdosq_column_double($st, $i); }
        if ($t === __McPdoSq::TYPE_BLOB) {
            $p = \__mc_pdosq_column_blob($st, $i);
            $n = \__mc_pdosq_column_bytes($st, $i);
            if (\ptr_to_int($p) === 0 || $n <= 0) { return ''; }
            return \str_from_buffer($p, $n);
        }
        $p = \__mc_pdosq_column_text($st, $i);
        $n = \__mc_pdosq_column_bytes($st, $i);
        if (\ptr_to_int($p) === 0) { return ''; }
        // str_from_buffer and not cstr_to_str: sqlite TEXT may contain a NUL and
        // php keeps every byte of it.
        return \str_from_buffer($p, $n);
    }

    public function rowCount(): int { return $this->changes; }

    public function closeCursor(): void
    {
        \__mc_pdosq_reset(\int_to_ptr($this->addr));
        $this->first = false;
        $this->exhausted = true;
    }

    public function close(): void
    {
        if ($this->closed || $this->addr === 0) { return; }
        \__mc_pdosq_finalize(\int_to_ptr($this->addr));
        $this->closed = true;
        $this->addr = 0;
    }

    public function errorInfo(): array { return __McPdo::triple('', 0, false, '', false); }

    public function __destruct()
    {
        $this->close();
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** One step, waiting out a BUSY the way {@see __McPdoSqliteDrv::drive} does. */
    private function stepOnce(\Ffi\Ptr $st): int
    {
        $attempt = 0;
        $deadline = \microtime(true) + $this->db->timeout;
        while (true) {
            $rc = \__mc_pdosq_step($st);
            if ($rc !== __McPdoSq::BUSY && $rc !== __McPdoSq::LOCKED) { return $rc; }
            $attempt = $attempt + 1;
            if (!__McPdoSq::backoff($attempt, $deadline)) { return $rc; }
        }
    }

    private function apply(\Ffi\Ptr $st, int $i, mixed $v, int $type): void
    {
        if ($v === null || $type === PDO::PARAM_NULL) {
            \__mc_pdosq_bind_null($st, $i);
            return;
        }
        if ($type === PDO::PARAM_INT || $type === PDO::PARAM_BOOL) {
            \__mc_pdosq_bind_int64($st, $i, (int) $v);
            return;
        }
        if ($type === PDO::PARAM_LOB) {
            $s = (string) $v;
            $n = \strlen($s);
            if ($n === 0) {
                // A zero-length blob still has to be a blob, and bind_blob with a
                // null pointer would store NULL instead.
                \__mc_pdosq_bind_blob($st, $i, \int_to_ptr(\str_bytes($s)), 0,
                                      __McPdoSq::TRANSIENT);
                return;
            }
            \__mc_pdosq_bind_blob($st, $i, \int_to_ptr(\str_bytes($s)), $n,
                                  __McPdoSq::TRANSIENT);
            return;
        }
        /*
         * PARAM_STR is php's default and its reach is wider than it looks:
         * `execute([1])` binds sqlite TEXT '1' and `execute([2.5])` binds TEXT
         * '2.5' — NOT an integer and NOT a REAL. php has no PARAM_FLOAT, so a
         * float genuinely goes in as text; binding a double here instead made
         * `typeof(v)` answer `real` where php says `text`. A caller wanting a
         * native storage class says PARAM_INT (or PARAM_LOB) explicitly.
         */
        $s = (string) $v;
        \__mc_pdosq_bind_text($st, $i, $s, \strlen($s), __McPdoSq::TRANSIENT);
    }
}

// ── The factory PDO::__construct dispatches to ──────────────────────────────

/**
 * Open a `sqlite:` DSN.
 *
 * Every DSN shape is sqlite's own: `sqlite::memory:` is the literal filename
 * `:memory:`, `sqlite:` with nothing after it is the empty name sqlite reads as
 * a private temporary database, and anything else is a path. There is nothing to
 * parse, which is why this takes the DSN remainder verbatim.
 */
function __mc_pdo_sqlite_open(string $rest, array $options): __McPdoDrv
{
    $flags = __McPdoSq::OPEN_READWRITE | __McPdoSq::OPEN_CREATE;
    if (isset($options[PDO::SQLITE_ATTR_OPEN_FLAGS])) {
        $flags = (int) $options[PDO::SQLITE_ATTR_OPEN_FLAGS];
    }

    $out = \__mc_pdosq_calloc(8, 1);
    $rc = \__mc_pdosq_open_v2($rest, $out, $flags, \int_to_ptr(0));
    $addr = \peek_i64($out, 0);
    \__mc_pdosq_free($out);

    if ($rc !== __McPdoSq::OK) {
        /*
         * ⚠ sqlite hands back a live handle even on failure, and the message is
         * only readable THROUGH it — so the error has to be read before the
         * handle is closed, and the handle has to be closed anyway.
         */
        $msg = 'unable to open database file';
        $ec = $rc;
        if ($addr !== 0) {
            $dbp = \int_to_ptr($addr);
            $ec = \__mc_pdosq_errcode($dbp);
            $msg = \cstr_to_str(\__mc_pdosq_errmsg($dbp));
            \__mc_pdosq_close_v2($dbp);
        }
        $fail = __McPdo::connectFail(__McPdoSq::sqlstate($ec), $ec, $msg);
        throw $fail;
    }
    if ($addr === 0) {
        throw __McPdo::connectFail('HY000', 0, 'unable to open database file');
    }

    $drv = new __McPdoSqliteDrv($addr);
    if (isset($options[PDO::ATTR_TIMEOUT])) {
        $drv->timeout = (float) $options[PDO::ATTR_TIMEOUT];
    }
    return $drv;
}
