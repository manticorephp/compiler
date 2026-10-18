# `ext/pdo` + `pdo_sqlite`, `pdo_pgsql`, `pdo_mysql`

PHP Data Objects with three drivers: SQLite (libsqlite3 over FFI), PostgreSQL and MySQL / MariaDB (the wire protocols in
pure PHP over a socket: no libpq, no libmysqlclient). `new PDO('sqlite::memory:')` works, and so do
prepared statements, the array fetch modes, transactions, and php's three error modes. The network drivers are
sections `pgsql:` and `mysql:` below.

```php
$db = new PDO('sqlite:/tmp/app.db');
$db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');

$ins = $db->prepare('INSERT INTO t (name) VALUES (:name)');
$ins->execute([':name' => 'ann']);
echo $db->lastInsertId();

foreach ($db->query('SELECT id, name FROM t')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['id'], ' ', $row['name'], "\n";
}
```

## Where it lives, and why

Prelude files, all global-namespace, all demand-gated on a program mentioning `PDO` /
`PDOStatement` / `PDOException` / `PDORow`:

| file | what |
|---|---|
| `prelude/pdo.php` | the driver-agnostic facade: `PDO`, `PDOStatement`, `PDOException`, `PDORow`, every `PDO::` constant, the fetch modes, the DSN split, and the `__McPdoDrv` / `__McPdoDrvStmt` seam |
| `prelude/pdo_sqlite.php` | the SQLite driver: ~30 libsqlite3 FFI bindings and the two classes that satisfy the seam |
| `prelude/pdo_sql.php` | what the two network drivers share: the SQL scanner (placeholders, quoting, comments) and the TLS handshake over libssl |
| `prelude/pdo_pgsql.php` | the PostgreSQL driver (protocol 3.0) |
| `prelude/pdo_mysql.php` | the MySQL / MariaDB driver (client/server protocol) |
| `prelude/pdo_pgsql_off.php`, `prelude/pdo_mysql_off.php` | stand-ins that answer `could not find driver` when the program does not name that driver (see below) |

**The prelude and not `src/Runtime/Stdlib`** for the same reason as ext/curl: the stdlib
`.o.sig` carries FUNCTIONS ONLY. `new PDO(...)` hands back an object and `PDO::FETCH_ASSOC` is
a class constant, and a class declared in the stdlib is never registered by the program holding
one of its instances — `instanceof` reads false and its properties come back as raw bits.

**Linking.** `#[\Ffi\Library('sqlite3')]` is the whole story: the emitter collects the library
off every wrapper it emits and `generic_link_flags()` resolves it through
`pkg-config --libs sqlite3`. A binary that never mentions PDO never emits a wrapper and never
links `-lsqlite3`. The network drivers add no library of their own: a socket, libssl (already in every binary) and, for
mysql `caching_sha2_password`, `openssl_public_encrypt` (every PDO program therefore carries the openssl prelude).

## The driver seam

`PDO` and `PDOStatement` hold no database knowledge. They delegate to two interfaces, and
everything database-shaped lives behind them. `pgsql:` and `mysql:` are the opposite shape to
sqlite — a socket and byte parsing, no FFI — and implement the same two interfaces without touching the facade.

SQLite deserves saying plainly: **it is not a server.** There is no wire protocol and nothing to
unpack; `sqlite3_step` is a `pread` on a local file. That is why this driver is FFI and the
network drivers will not be.

Adding a driver means: implement `__McPdoDrv` + `__McPdoDrvStmt`, add one arm to the scheme
`match` in `PDO::__construct`, and add the driver to the gate in `Main.php`.

**The driver gate.** A DSN scheme is a runtime **string** and `PreludeDemand` is token-based, so the compiler cannot know which
driver `new PDO($dsn)` will open. It reads the construction sites instead (`new PDO(..)`, `PDO::connect(..)`):

- when EVERY site starts its DSN with a literal `<driver>:` (`new PDO('sqlite::memory:')`, `new PDO("pgsql:host={$h}")`), the program links sqlite plus
  the drivers those literals name; the other network driver is replaced by its `*_off.php` stand-in and opening it throws
  `could not find driver (pdo_pgsql is not linked: ...)`;
- any other site (a variable DSN, `getenv('DSN') ?: 'sqlite::memory:'`, a call, `class X extends PDO`), or a program with no site at all
  (`function repo(PDO $db)` only), links every driver. A sqlite-only program that builds its DSN in a variable therefore pays for both network drivers;
- the one hole is a site the scan cannot see (`new $cls('pgsql:..')` with a class name in a variable): spell a literal `pgsql:` DSN anywhere in a `new PDO(..)` to link it.

The point is compile time: loading both network drivers (and the openssl prelude they need) into a small sqlite program cost about 40% of its compile
time (19.5 s against 13.7 s measured on `pdo_sqlite_basic`); with the gate it compiles as fast as before the drivers existed. Tests:
`pdo_gate_sqlite_only`, `pdo_gate_pgsql_only`, `pdo_gate_dynamic_dsn`.

## Why there are no trampolines here

ext/curl needed four fixed trampolines because libcurl takes callbacks. This driver takes none:
`sqlite3_exec`'s row callback is avoidable by driving `prepare`/`step` directly, so nothing here
runs PHP on a C frame and the "an exception must not escape a callback" hazard never arises.

## No placeholder rewriting

sqlite binds `?`, `:name`, `@name` and `$name` natively, and `sqlite3_bind_parameter_index()`
maps a name to its index exactly — including the quoting and comment rules it already had to
implement to parse the statement. `SELECT ':id' AS lit WHERE id = :id -- :nope` binds one
parameter, and we did not write a line of SQL scanning to get that. An emulated-prepare driver
would need its own scanner; that is the driver's business, not the facade's.

## Async

The API is synchronous, like Zend's. For sqlite the scheduler shows up in one place: **`SQLITE_BUSY`**. (The network drivers park the fiber in
every socket read; see "Async" under `pgsql:` and `mysql:` below.)

`sqlite3_busy_timeout` is never called — it sleeps inside a C frame, which stalls the whole
event loop, netpoller included, so one contended database would freeze every unrelated fiber in
an `Http\Server` process. Instead the driver owns its retry loop and every retry goes through
`__McPdoSq::backoff()`, which parks the fiber when a scheduler is running and falls back to
`usleep()` when one is not. The wait is bounded by `PDO::ATTR_TIMEOUT` (30 s default).

The hook is `\Runtime\AsyncHook` and deliberately **not** `Async\`: `prelude/async.php` is
demand-gated on a program mentioning `Async\`, so naming it here would drag the entire scheduler
into every synchronous PDO program. `AsyncHook` lives in `src/` and is always present; with no
scheduler installed `active()` is one null check.

`sqlite3_progress_handler` is deliberately **not** bound. A fiber here is a stackful fcontext
switch, and every suspend point can raise `CancelledException` — including on resume. That
throw would longjmp past sqlite's own half-updated frames, which `docs/ffi.md` forbids.

## Deliberate divergences from php

- **`PDOException::getCode()` returns the driver's integer code, not a SQLSTATE string.**
  `Throwable::getCode(): int` is an interface contract here (`prelude/exceptions.php`), and php
  is itself inconsistent — a connect failure already yields int `14`. The SQLSTATE is in
  `errorInfo[0]` and in the message, exactly where php puts them.
- **`unset()` of a variable held by a stored reference does not disconnect it** (`bindColumn()` / `bindParam()` variable unset between bind and fetch/execute): a later fetch still writes through the reference and revives the variable, and an `execute()` after the unset binds `0`/null, not the old value. Compiler bug #340.
- **Two or more `bindColumn()`/`bindParam()` locals of a function that returns the statement, outliving them, can corrupt memory** (the statement keeps references to locals whose frame is gone). Compiler bug #341; bind to variables that outlive the statement.
- **`ATTR_PERSISTENT` is accepted and ignored.** One process, no persistent pool (see Async\Pool below). The constructor option is kept for
  `getAttribute()`; `setAttribute()` answers `false` afterwards (php, all drivers).
- **`getAvailableDrivers()` is the fixed list `['mysql', 'pgsql', 'sqlite']`**, where php lists the drivers its build has. It does
  not follow the driver gate: a driver the gate left out is listed and then answers `could not find driver`.
- **Where php warns and carries on, the network drivers throw** (a NUL byte in a pgsql DSN value, where php truncates; `getNotify()` with a
  timeout above `2147483647`, where php clamps; a mysql statement mixing `?` and `:name`, refused at `prepare()` where php refuses at
  `execute()`).
- **No `Pdo\Pgsql` / `Pdo\Mysql` subclasses**: their methods live on `PDO` itself and `PDO::connect()` returns a `PDO`.
- **A dump shows php's shape, `var_export` / `json_encode` / `(array)` do not**: `print_r` / `var_dump` of a `PDO` print an empty object (a `PDOStatement` its `queryString`) through `__debugInfo()`, but the
  facade's bookkeeping properties are public, so `var_export`, `json_encode` and `(array)` list them (driver object, error state, fetch mode). No credential is among them: the mysql password lives in a
  static table, never in an instance property. `get_object_vars()` outside the class also lists private properties (#337).

`getColumnMeta()['table']` is **not** a divergence: `sqlite3_column_table_name()` is bound
`#[\Ffi\Weak]` and called only when `sqlite3_compileoption_used('ENABLE_COLUMN_METADATA')` says
this libsqlite3 has it — sqlite's own answer, so no dlsym and no OS branch. A build without the
option omits the key, which is also what php does for an expression column.

Cursor orientation is not one either: `$cursorOrientation` and `$cursorOffset` are accepted and
ignored, exactly as pdo_sqlite does — a sqlite statement is forward-only and php returns the
next row whatever is asked for (measured: `ORI_PRIOR` / `FIRST` / `LAST` / `ABS` / `REL` all
behave as `ORI_NEXT`). Throwing was stricter than the oracle.

## `pgsql:` (prelude/pdo_pgsql.php)

Protocol 3.0 over a plain socket. DSN: `pgsql:host=..;port=..;dbname=..` plus `hostaddr`, `user`, `password` and the `ssl*` keys of the TLS
section below. `user=` / `password=` in the DSN beat the constructor arguments (php does the same); `host` defaults to `localhost`,
`port` to 5432, `dbname` to the user. Authentication: `trust`, cleartext, `md5`, `SCRAM-SHA-256` (the server must prove its identity, nonce and
iteration count are validated). Statements are always server-side (extended protocol, named statements; `ATTR_EMULATE_PREPARES` is stored and
ignored); `PDO::PGSQL_ATTR_DISABLE_PREPARES` re-parses an unnamed statement at every `execute()`. Placeholders `?` and `:name` are rewritten
to `$n` by the shared scanner (comments, quotes, dollar quoting, `??`). Values are typed like pdo_pgsql (ints, floats, bool, bytea, text).
`errorInfo` carries the server's SQLSTATE and message in libpq's rendering; a connect failure is `SQLSTATE[08006] [7] ..`.
Attributes: `ATTR_SERVER_VERSION`, `ATTR_SERVER_INFO` (from ParameterStatus), `ATTR_CONNECTION_STATUS`, `ATTR_CLIENT_VERSION`
(`manticore-pgwire 3.0`), `ATTR_TIMEOUT`, `PGSQL_ATTR_FETCH_BATCH` (superset, see Unbuffered cursors). Further sections: TLS, LISTEN/NOTIFY and
COPY, unbuffered cursors, pooling.

**Async (shared with mysql).** A socket read parks the fiber, so a query never stalls the loop; one connection is one protocol state machine:

- *Busy gate.* While one fiber's request is on the wire, another fiber using the same `PDO` waits for it (polling the scheduler every 1 ms,
  bounded by the connection timeout, then a `PDOException`); with no scheduler a second use of a busy handle throws `connection busy`.
  Without the gate two fibers interleave bytes and each reads the other's result (reproduced before the fix).
- *Poisoning.* A request that does not finish normally (fiber cancelled, `ATTR_TIMEOUT` expired, a malformed or truncated message, a write
  failure) leaves the stream in an unknown place, so the connection is closed at once: the next use throws a connection error, it is never
  answered from half a response. A server `ErrorResponse` is not a poison: the response is complete and the handle keeps working.
- *Cancel on the server.* An abandoned request also stops the work on the server: pgsql sends a `CancelRequest` (pid + secret on a throwaway
  socket), mysql `KILL QUERY` from a second connection; both run under `Async\shield` and swallow their own errors.
- Tests (Manticore-only, hand-written expected output): `pgsql_async_*`, `mysql_async_*`.

## `mysql:` (prelude/pdo_mysql.php)

The client/server protocol over a plain socket, no libmysqlclient. Text protocol with emulated
(client-side) prepares, as pdo_mysql defaults to (`ATTR_EMULATE_PREPARES=false` selects the binary protocol, below); typed like mysqlnd (ints and floats native,
DECIMAL / DATE / YEAR / strings as text, BIT as an integer, an UNSIGNED BIGINT above `PHP_INT_MAX` as a
string). Connection options by number: `1004` FOUND_ROWS, `1002` INIT_COMMAND, `1012`
MULTI_STATEMENTS (on by default, as php), `ATTR_TIMEOUT`.

- Authentication: `mysql_native_password`, `caching_sha2_password` (fast path; full authentication
  over a plain socket RSA-encrypts the password through `openssl_public_encrypt`; every PDO program carries
  the openssl prelude for that),
  auth switch between the two; `sha256_password` (cleartext + NUL inside TLS, RSA key exchange on a plain socket, as mysqlnd;
  an unknown user makes the server fake this plugin, and the login ends in php's `[1045] Access denied`).
  `mysql_clear_password`, MariaDB `ed25519` / `parsec`: `[2054] The server requested authentication method unknown to the client [<plugin>]`, as mysqlnd.
- php reads no `user=` / `password=` DSN key for mysql (unlike pgsql): the constructor arguments rule.
- `exec()` of several statements answers the FIRST statement's count and runs all of them; `query()`
  of several keeps every result for `nextRowset()` (the one driver where it is not IM001). A SELECT
  through `exec()` is consumed here; php leaves its result pending and fails the next command with 2014.
- `NO_BACKSLASH_ESCAPES` is read off the server status flag of each OK/EOF packet, so `quote()` follows
  a mid-session `SET sql_mode`; invalid UTF-8 is escaped byte by byte, never `false` (mysqlnd).
- `errorInfo` of a core-raised HY093 is `[state, null, message]`; php has `[state, 0]`.
- `quote()` assumes the charset the connection was opened with: a `SET NAMES gbk`-style statement issued by user code is not tracked (the same weakness as mysqlnd).
- Timeouts (both network drivers): php's `ATTR_TIMEOUT` is a CONNECT timeout. Here connect + authentication and every
  message already begun (mid-message deadline: 2 x timeout + n/64 KiB, a defence against drip-feeding) are always
  bounded. The idle wait for the START of any message of a response (the first one, and every later one: after a NOTICE, between
  the result sets of a multi-statement `exec()` or `CALL`, between slow rows) is unbounded unless the program set `ATTR_TIMEOUT`
  itself (constructor options or `setAttribute`), which then bounds each such wait to that timeout (superset behaviour). Tests:
  hostile `idle_between` (a stall between messages must succeed past 30 s), `stall_mid` (a stall inside one must fail),
  `pgsql_long_after_notice`, `mysql_long_multi`.
  A default-config connection to a silent peer (no FIN, no RST) therefore waits at message boundaries, as php does for a running statement. What is always
  bounded: a message that has begun (including the continuation frames of a mysql packet above 16 MB), connect + authentication, and every close or drain
  (`closeCursor()`, destruction, `resetSession()`, the Close of a suspended pgsql portal): at most 5 s (the connection timeout when shorter), after which the
  connection is closed (hostile `us_drain_idle_default`, `fb_batch_close_idle_default`). Async cancellation / scope deadlines are the idiomatic way to bound a statement.
- Cancellation: a query abandoned mid-flight (fiber cancelled, `ATTR_TIMEOUT` expired; not on EOF) is stopped on the server with
  `KILL QUERY <connection id>` from a second connection with the same credentials and TLS options (about 2 s at most, every error
  swallowed, run under `Async\shield`); the handle itself is dead (`2006`), as php. `mysql_async_cancel` proves the query is gone from `information_schema.processlist`.
- `MYSQL_ATTR_LOCAL_INFILE=true` throws at connect (`LOCAL INFILE is not supported`).
- Errors: an ERR packet becomes `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry ...`
  (state from the packet's `#` marker, descriptions from the shared 266-row table, unlisted states read
  `<<Unknown error>>`), `errorInfo = [state, errno, message]`, `getCode()` = the errno (php: the SQLSTATE string, see the
  divergence above). A connect failure is always `SQLSTATE[HY000] [errno] text`, whatever the server state (php). The
  dead-connection family is php's `2006 MySQL server has gone away` for EOF, reset AND read timeout alike (mysqlnd never
  says 2013); the first command after the server dropped an idle connection (`wait_timeout`) reads the server's own
  `4031` ERR, whose sequence number is the previous exchange's. Every later call fails at once, nothing hangs, the socket
  is closed once.
- Transactions follow the server: `inTransaction()` is `SERVER_STATUS_IN_TRANS` of the latest OK/EOF packet, so implicit
  commits (real DDL; temporary-table DDL does not commit), `START TRANSACTION` issued through `exec()` and
  `autocommit=0` are all seen, and a connection killed inside a transaction still answers `true`. `begin`/`commit`/
  `rollBack` do not touch `errorInfo` on success (php). `ATTR_AUTOCOMMIT` runs `SET autocommit=0|1` (a non-bool value is
  php's `TypeError`).
- Attributes: `ATTR_SERVER_INFO` is `COM_STATISTICS` (`Uptime: .. Threads: ..`); `ATTR_CLIENT_VERSION` is
  `manticore-mysqlwire 4.1` (php: `mysqlnd 8.x`); `ATTR_CONNECTION_STATUS` is `<host> via TCP/IP` (`localhost` is TCP here,
  php would use the unix socket); `ATTR_FETCH_TABLE_NAMES` is stored and read back but column names are not prefixed;
  `ATTR_DEFAULT_STR_PARAM = PARAM_STR_NATL` makes `quote()` and bound strings `N'..'`. Once connected, the connect-time
  options (`1004`, `1012`, `1002`, `1005`, `ATTR_PREFETCH`) answer `false` on `setAttribute()`, as php; `ATTR_PERSISTENT`
  is kept from the constructor and `false` afterwards; `ATTR_TIMEOUT` answers `false` yet takes effect (see Timeouts).
- Refused loudly (php accepts them): `MYSQL_ATTR_COMPRESS=true` (`compression is not supported`). `MYSQL_ATTR_USE_BUFFERED_QUERY=false` works (see Unbuffered cursors). `exec()` of a statement that returns rows consumes the result (php leaves it pending and
  fails the next command with 2014 / 'pending result sets').
- A deadlock (1213) cannot be provoked by two connections inside one thread; the tests raise it with `SIGNAL`.
- TLS: any of `MYSQL_ATTR_SSL_KEY` / `_CERT` / `_CA` / `_CAPATH` / `_CIPHER` turns it on (`MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` alone does
  not, as mysqlnd); the server is then verified, chain AND host name, unless `SSL_VERIFY_SERVER_CERT` is `false` (php's default is
  verify), in which case no CA is read at all. Without `_CA`/`_CAPATH` the host's default trust store is used. `_CIPHER` is the
  OpenSSL cipher list (TLS 1.2 and below; TLS 1.3 suites are not affected, as in php), an empty or unknown list fails. `_CERT` must
  load and `_KEY` (default: the certificate file) must match it; `_KEY` without `_CERT` is ignored (measured). Every TLS failure
  reads `SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL`; a server without `CLIENT_SSL` reads `[2006]`, as mysqlnd. The
  SSLRequest is sequence 1, the handshake response sequence 2 inside TLS; caching_sha2 full authentication sends the password +
  NUL inside TLS instead of the RSA exchange. Hostile servers: bytes buffered after the handshake packet, or arriving between the
  SSLRequest and the TLS handshake, end the attempt (plaintext injected before TLS must never be read as part of the session).
  The new constants are the same ids under `Pdo\Mysql::ATTR_*`; the old names are what the tests use (`PDO::MYSQL_ATTR_*` is deprecated in 8.5).
- Async: the busy gate, poisoning and `KILL QUERY` cancellation are the ones described under `pgsql:`; the mysql wire uses the same guard shape.
- Gaps: unix sockets, compression, `COM_STMT_SEND_LONG_DATA`, a few charset names (`charset=` knows utf8mb4, utf8,
  latin1, ascii, binary, latin2, cp1250, cp1251, koi8r/u, greek, hebrew), AUTO_INCREMENT ids above
  `PHP_INT_MAX`.

### Native prepared statements (`ATTR_EMULATE_PREPARES=false`)

Set in the constructor options or with `setAttribute()` (read at each `prepare()`, so the two kinds can be mixed on one
connection). `prepare()` then sends `COM_STMT_PREPARE`: a syntax error, an unknown table or column (1064 / 1146 / 1054) and
the `max_prepared_stmt_count` limit (1461) are raised by `prepare()`, not by `execute()` (emulated statements fail at
`execute()`). `execute()` sends `COM_STMT_EXECUTE` with typed parameters and reads binary rows; the statement is released with
`COM_STMT_CLOSE` exactly once when the statement object goes away (or immediately when the connection is busy: queued, sent
ahead of the next command), and a close on a dead connection is a no-op. Everything below was measured against php 8.5.

- Placeholders: `?` and `:name` (rewritten to `?` for the server, the order kept). A repeated `:name` is refused with
  `SQLSTATE[HY093]: Invalid parameter number` at `execute()` (pdo_mysql cannot bind it natively), as is any count mismatch
  (too few, too many, extra `execute([..])` values: the refused parameter is not kept, the earlier ones stay bound) and a
  statement without placeholders given values. `?` mixed with `:name` is refused at `prepare()`.
- Parameter types: NULL / `PARAM_NULL` is NULL; `PARAM_STR` (and every `execute([..])` value) sends a string (`true` is `"1"`,
  `false` `""`); `PARAM_INT` / `PARAM_BOOL` / `PARAM_LOB` keep the value's own type: int / bool -> LONGLONG (`PARAM_BOOL` folds an
  int to 0/1), float -> DOUBLE, string -> VAR_STRING, a stream -> its contents. `select ?` therefore hands back an int for
  `bindValue(1, 5, PARAM_INT)` and a string for `execute([5])`.
- Large values: a value travels inline in the execute packet (split at 16 MB like any packet); `COM_STMT_SEND_LONG_DATA` is not
  used, and neither does pdo_mysql. A value above the server's `max_allowed_packet` is answered by the server (it closes the
  connection: `2006`), not pre-checked.
- Result typing (mysqlnd, binary protocol): TINY / SHORT / INT24 / LONG / LONGLONG -> int (UNSIGNED BIGINT above `PHP_INT_MAX`
  stays a string), DOUBLE -> float exactly, FLOAT -> the double of its `%.Nf` text (fixed decimals) or of 6 significant digits
  (`0.1f` is `0.1`), BIT -> int (a 64-bit value with the top bit set: string), YEAR / DECIMAL / JSON / ENUM / SET / strings / blobs
  -> string, DATE / DATETIME / TIMESTAMP / TIME -> `YYYY-MM-DD[ HH:MM:SS[.ffffff]]` with as many fraction digits as the column's
  decimals, zero dates as zeros, a negative TIME with its sign and the days folded into the hours.
- Differences from the emulated mode that are php's own: `columnCount()` is 0 until `execute()`; the closing OK of a `CALL`
  is one more `nextRowset()` step that still reports the previous result's columns; `rowCount()` after a failed `execute()` is the
  previous statement's; re-executing without draining a `CALL` is allowed, `getColumnMeta()` reads the execute-time definitions.
- Not reproduced: error 2014 for a second statement started while another one's `CALL` results are pending (results are always
  fully buffered here); a placeholder count the server sees differently from the scanner (`1210 Incorrect arguments to
  mysqld_stmt_execute`) is raised locally before anything is sent.
- Hostile servers: the PREPARE_OK length (exactly 12), the column count (4096) and the parameter count (65535) are capped before
  anything is read; parameter / column definition counts must match the announcement and be followed by their EOF; every binary
  row is bounds-checked (`$l > $len - $o`, never `$o + $l > $len`), the NULL bitmap must fit, DATETIME length must be 0/4/7/11 and
  TIME length 0/8/12, a length-encoded string above 2^31 or with the top bit set is refused, trailing bytes are refused. Each ends the
  connection with `[2027] Malformed packet`, closing the socket once. The one exception is a statement whose server-side
  placeholder count differs from the scanner's: that is a local, non-fatal `HY000 1210` raised before anything is sent, and the
  connection stays usable.

### MariaDB

`bash tests/pdo_net/run.sh --server mariadb -k mysql_` runs the same `mysql_*` cases against `mc-maria` (mariadb:11, `:33061`; `--server mysql` is the default,
`mc-my`). Each case is graded against live php on THAT server. A case header line `// servers: mysql` (or `mariadb`) limits it; MySQL-only: the
caching_sha2 / TLS / `mysql_native_password` login cases and the three global-state native-prepare cases (they need `mc-my-iso`).

Supported, byte-identical to php/mysqlnd on mariadb:11 in the suite: `mysql_native_password`, text and binary protocol, `INSERT ... RETURNING` / `DELETE ... RETURNING`
through `query()` and `prepare()`/`execute()` (`rowCount`, `columnCount`, rows; `lastInsertId` stays `0`, as php), the JSON (a BLOB-flagged text), `UUID` and `INET6` column
types (`getColumnMeta`), MariaDB error texts and codes (always the server's own, never a hard-coded MySQL string), the `5.5.5-` replication prefix of `ATTR_SERVER_VERSION`
(stripped as mysqlnd does), the optional `Flush tables:` of `ATTR_SERVER_INFO`. A PARAM_BOOL / bool-typed parameter of the binary protocol travels as TINY (MariaDB reports that
column type back, MySQL widens it). The driver sends no MariaDB extended capability flags and reads none.

Not supported: `ed25519` / `parsec` logins (`[2054]`, as php). `COM_RESET_CONNECTION` is sent by `resetSession()` (see Pooling).

Where the driver deliberately differs from php (both servers): `exec()` of a statement that returns rows (`SELECT`, `... RETURNING`) answers `0` and the connection stays
usable; php (measured, 8.5, both servers) leaves the result pending and fails EVERY later command on that handle with `2014 Cannot execute queries while other unbuffered queries are active`.
Reproducing that wedge was judged a footgun, not a feature.

## `pgsql:` TLS (prelude/pdo_pgsql.php)

DSN keys, graded against libpq: `sslmode` = `disable | allow | prefer` (default) `| require | verify-ca | verify-full`, `sslrootcert`,
`sslcert`, `sslkey`, `sslcrl`; `sslsni` is honoured (`0` stops SNI), `sslcompression` / `sslcrldir` / `sslpassword` are accepted and ignored.
An unknown `ssl*` key is `invalid connection option "x"`, a bad mode `invalid sslmode value: "x"` (an EMPTY value swallows the next
token like php's connect string does: `invalid sslmode value: "sslrootcert=/x"`, or `"connect_timeout=30"` when last). `hostaddr` connects to the
address while `host` is what the certificate is checked against.

- Protocol: SSLRequest, then exactly ONE reply byte. `S` starts the handshake; `N` continues in plaintext only for `prefer`
  (`server does not support SSL, but SSL was required` otherwise); `E` is `server sent an error response during SSL exchange`;
  any other byte `received invalid response to SSL negotiation: X`. After an `S`, any further byte already buffered or
  arriving before the ClientHello is sent ends the attempt with `received unencrypted data after SSL response` and is never
  retried (CVE-2021-23222: injected plaintext must not be parsed as part of the encrypted session). Nothing of the startup
  packet, the password or any SCRAM message is written before the handshake is done.
- libpq's order: `allow` tries plaintext and then TLS, `prefer` TLS and then plaintext, on ANY failure after the TCP connection
  was made; the messages of both attempts are joined with a newline. A refused TCP connect is not retried.
- Verification as libpq: a root file that exists turns chain verification on for EVERY mode (`require` + a wrong
  `sslrootcert` fails); `verify-ca` / `verify-full` need the file (`root certificate file "x" does not exist ...`);
  `verify-full` also matches the host (DNS or IP SAN, CN only without SAN; `server certificate for "a" (and N other names)
  does not match host name "b"`). `sslrootcert=system` uses the host store and is `verify-full` by default (`weak sslmode ...` for the
  others). Defaults are `~/.postgresql/{postgresql.crt,postgresql.key,root.crt,root.crl}`. A client certificate whose file is missing is
  skipped; its key file must exist and not be group/world readable (libpq's wording). `sslcrl` that exists enables CRL checking of
  the whole chain; a missing one is ignored.
- Protocol range: `ssl_min_protocol_version` (default `TLSv1.2`) and `ssl_max_protocol_version` as libpq (`invalid "ssl_min_protocol_version" value: "x"`,
  `invalid SSL protocol version range`); the hint names the configured range. mysql uses the same TLS 1.2 floor (mysqlnd has no option;
  the local OpenSSL cannot offer TLS 1.1, so the floor is tested with a TLS-1.2-capped server against a 1.3-only client).
- Cancelling a fiber parked in the handshake frees the SSL object and the socket (`pgsql_async_tls_cancel`); the stdlib's own handshake still
  leaks in that case (#328, which also lists the ssl.* context options `stream_socket_enable_crypto` ignores).
- Failures read `SSL error: <OpenSSL reason>` (plus libpq's protocol-version hint) or the libpq texts above; a silent peer is
  `timeout expired` within `ATTR_TIMEOUT`.
- Deviations: PG* environment variables are not read (`PGSSLMODE` ...); the key-file permission check runs before the certificate is
  parsed (libpq reads the certificate first, which only matters when both are broken); `sslpassword` (encrypted keys) and `sslcrldir` are not supported;
  `ATTR_CONNECTION_STATUS` is unchanged. Both drivers run the handshake themselves over libssl (the stdlib's
  `stream_socket_enable_crypto()` knows no CA file, client certificate, cipher list or CRL).
- Tests: `tests/pdo_net/up.sh` starts `mc-pg-ssl` (:54323; hba allows `u` over TLS only, `cu` by client certificate) and `mc-my-ssl`
  (:33064; a certificate naming 127.0.0.1, user `cu` requires a client certificate) with a throwaway CA generated into
  `tests/pdo_net/.certs` (git-ignored, removed by `down.sh`).

## `pgsql:` LISTEN/NOTIFY and COPY (prelude/pdo_pgsql.php)

`getNotify(int $fetchMode = FETCH_DEFAULT, int $timeoutMilliseconds = 0): array|false`, `getPid()`, `copyFromArray()`, `copyFromFile()`, `copyToArray()`,
`copyToFile()` (php 8.4 names) and their `pgsql*` aliases are methods of `PDO` itself (php puts them on `Pdo\Pgsql`; there is no such subclass here, so
`PDO::connect()` returns a `PDO`). On a connection that is not pgsql they throw `Error: Call to undefined method PDO::getPid()`, as php does for the aliases.
The aliases raise no deprecation (the superset throws instead of warning).

- Notifications: a `NotificationResponse` that arrives between any two messages (inside a query's response too) is queued; `getNotify()` pops the queue,
  else waits up to the timeout (0 only looks, without waiting). The wait is a read with a stream timeout, so under the scheduler the fiber parks and the
  loop runs (`pgsql_async_notify`); cancelling the fiber loses nothing and the connection stays usable. Row shape as php: ASSOC `message`/`pid`/`payload`,
  NUM `0/1/2`, BOTH (default) both; `payload` only when non-empty. Deviations: a timeout above `2147483647` is a `ValueError` (php warns and clamps); the wait holds
  the connection, so a query on it from another fiber waits behind it (bounded by `ATTR_TIMEOUT`); past 100000 unread notifications, or 32 MiB of them (payload + channel + 64 bytes each), the OLDEST is dropped (libpq queues without limit; the session goes on); while idle in `getNotify()` any message but `A` / `S` / `N` / `E` announcing more than 64 MiB is refused at once (`invalid message length`).
- COPY: simple-protocol `COPY t [(fields)] FROM|TO STDIN WITH DELIMITER E'<first byte of separator>' NULL AS E'<nullAs>'` built exactly as php builds it (names are
  interpolated raw, no quoting; an empty separator is a tab; the default `nullAs` is `\\N`), so the server's error text matches php's. `copyFromArray` takes an
  array or a Traversable, skips `''` rows and adds the missing newline; data goes out as CopyData frames of at most 64 KiB, then CopyDone. A server error is
  a `PDOException` (`false` under the other error modes) after the stream is drained: the connection is usable, the load is all-or-nothing, and inside a
  transaction it is aborted like after any error. `copyToFile` truncates the file before it asks the server (as php); `copyFromFile` reads the file whole through a handle, so a FIFO works too
  (php streams it line by line); `copyFromArray` turns every row into text before the first byte is sent (php sends row by row and consumes a generator lazily).
  The wait between CopyDone and the server's answer (a slow commit or index build) is not bounded by the default timeout, as for any statement; a `copyToFile` keeps one handle for the whole transfer. Unopenable files are `HY000 Unable to open the file[ for writing]`.
- The other direction smuggled through the table name (`'t TO STDOUT; --'` in `copyFromArray`) is `HY000` with an empty message; the session goes on.
- Tests: `pgsql_notify`, `pgsql_copy` (byte parity with php), `pgsql_async_notify` (hand-written), hostile-server modes `notify_*`, `copy_out_cap`, `copy_in_err`.

## Unbuffered cursors

**mysql** (`MYSQL_ATTR_USE_BUFFERED_QUERY = false`, at connect or `setAttribute`; byte parity with php, both protocols, both servers).
`query()` / `execute()` read the column definitions and stop; every `fetch()` reads one row packet off the socket, so memory stays flat
whatever the result size (300 000 rows are tested). While the cursor is open the connection belongs to it:

- another command on the handle (`exec`, `query`, `prepare` of a native statement, `beginTransaction`, ...) throws php's
  `SQLSTATE[HY000]: General error: 2014 Cannot execute queries while other unbuffered queries are active.  Consider using ...` -- or, when the
  server announced more results of a multi-statement query, `... while there are pending result sets ...`, with php's text. `quote()`,
  `lastInsertId()` and `inTransaction()` need no round trip and keep working. `exec()` is always buffered.
- the cursor ends when the last row (and the last `nextRowset()`) is fetched, on `closeCursor()`, when the statement is executed again, or when it
  is destroyed. Closing early reads and discards the remaining rows (framing is checked, content is not decoded); a drain that outlasts
  `ATTR_TIMEOUT` closes the connection instead, so a huge unread result costs at most that long and the handle is never reused with half a result on it.
- an error after some rows (killed query, a function failing on row N) is raised by the `fetch()` that reaches it, with the rows before it
  delivered; the connection stays usable (verified against php with a JSON error mid-result).
- `rowCount()` of a streamed `SELECT` is `0` (mysqlnd), `columnCount()` is known at once.
- Under a scheduler the owner of the cursor gets the 2014 error; another task waits behind it (bounded by `ATTR_TIMEOUT`) and runs when the
  cursor ends. Cancelling a task parked inside a row read, or destroying its half-read cursor while it is being cancelled, closes the
  connection (`KILL QUERY` is sent): the next use reports `2006`, never a stream with half a result on it.
- Per-statement `prepare(..., [MYSQL_ATTR_USE_BUFFERED_QUERY => false])` is not read; the attribute is per connection.
- Hostile servers: a row that overruns its packet, has trailing bytes or too few columns, a length beyond the packet, truncated streams, a
  missing closing EOF, out-of-order sequence ids, a stall inside a row, a stream that never ends while closing (`us_*` modes of the hostile harness).

**pgsql** (superset: php has no cursor attribute, so no oracle; tests are hand-written). `PDO::PGSQL_ATTR_FETCH_BATCH = <rows>` (`1300`;
`0`, the default, buffers as before; negative or above 2^31-1 is a `ValueError`) executes the portal with a row limit. The server answers
`PortalSuspended` after each batch and `fetch()` asks for the next one with another `Execute`; the `Sync` is sent only when the portal finishes
(or fails), so memory holds one batch. Rows are identical to the buffered run for every batch size (1, 7, 999, 1000, 1001, 5000 against 1000 rows).

- The open portal owns the protocol state, so the same rules apply: a second statement on the handle throws
  `SQLSTATE[HY000]: General error: cannot run another statement while a PGSQL_ATTR_FETCH_BATCH cursor is open: fetch it to the end or call closeCursor()`,
  another task waits. A `Close(S)` of a destroyed statement is deferred until the portal is gone (a `Sync` would destroy it).
- `closeCursor()`, a re-`execute()` and destruction send `Close(portal)` + `Sync`: one round trip, whatever the result size; no rows are read.
  Inside a transaction block the portal is closed explicitly and the transaction goes on.
- An error in the first batch (syntax error, a failing row among the first N) is raised by `execute()`, as without batching; an error in a later batch is raised by
  the `fetch()` that runs out of the rows preceding it. In a transaction the block is then aborted (`25P02`), as always.
- `rowCount()` of a batched `SELECT` is the number of rows received so far (the total at the end); `columnCount()` is known at once.
- Cancellation parked in a batch read, or while the cursor's `Close` is in flight, closes the connection (`CancelRequest` is sent): the next use reports a connection error.
- Hostile servers: more rows than the limit, a non-empty `PortalSuspended`, a field length beyond its `DataRow` in a later batch, a closed
  connection or a stall between batches, a `ReadyForQuery` where a batch was expected, a `Close` that is never answered (`fb_batch_*`).
- Tests: `mysql_unbuffered` (php oracle), `mysql_unbuffered_big`, `mysql_unbuffered_cancel`, `mysql_unbuffered_fiber`, `pgsql_fetch_batch`, `pgsql_fetch_batch_async`,
  hostile modes `us_*` and `fb_batch_*`.

## Pooling and session reset

Superset (no Zend oracle). `PDO::resetSession(): void` returns the session to a fresh-connection state;
`PDO::ping(): bool` is true while the server answers (a lost connection is `false`, never an exception).
They are the `reset()` / `ping()` methods of the driver seam and what [`Async\Pool::pdo()`](async.md#connection-pool) calls.

| driver | `resetSession()` | `ping()` |
|---|---|---|
| sqlite | rolls back an open transaction | always true |
| pgsql | closes an open batched portal, `ROLLBACK` if a transaction is open, then `DISCARD ALL` (session settings, temp tables, prepared statements, advisory locks, `LISTEN`s) | an empty query (one round trip) |
| mysql / MariaDB | drains an unbuffered result (bounded by the connection timeout), drops the `COM_STMT_CLOSE`s still owed, `COM_RESET_CONNECTION` (rollback, session variables, temp tables, prepared statements), then restores the construction-time session: `SET NAMES <DSN charset>`, `ATTR_AUTOCOMMIT` (re-applied when the constructor option was false; the driver flag follows) and `MYSQL_ATTR_INIT_COMMAND` | `COM_PING` |

`resetSession()` throws `PDOException` in **every** error mode (silent and warning included): a caller that pools connections must learn that a connection could not be reset.
Both honour the busy gate (another fiber's statement finishes first) and an open cursor of the calling fiber
(`ping()` answers false, `resetSession()` closes it); an open cursor that belongs to ANOTHER fiber makes `resetSession()` throw (the owner's rows are not ours to drop).
What the program changed on the handle since the constructor is undone as well: `ATTR_ERRMODE`, `ATTR_DEFAULT_FETCH_MODE`, `ATTR_CASE`, `ATTR_ORACLE_NULLS`,
`ATTR_STRINGIFY_FETCHES`, `ATTR_STATEMENT_CLASS`, the recorded error state, and the driver's own attributes (`PGSQL_ATTR_FETCH_BATCH`, `PGSQL_ATTR_DISABLE_PREPARES`,
`ATTR_EMULATE_PREPARES`, `MYSQL_ATTR_USE_BUFFERED_QUERY`, `ATTR_TIMEOUT` and whether it bounds the idle wait, `ATTR_DEFAULT_STR_PARAM`, `ATTR_FETCH_TABLE_NAMES`) return to their
construction-time values; unread pgsql notifications are dropped. Statements prepared before a reset are invalid afterwards
(mysql ids carry a generation, so a stale statement object never closes a newer statement of the same id).
A server that does not know `COM_RESET_CONNECTION` (MySQL < 5.7.3, MariaDB < 10.2) makes `resetSession()` throw, which a pool treats as "discard".

Tests: `async_pool_basic`, `async_pool_cancel`, `pdo_sqlite_reset` (AOT), `pgsql_pool`, `mysql_pool`, `pgsql_reset_attrs`, `mysql_reset_attrs` (pdo_net, both mysql servers).

## Limitations

`nextRowset()` reports IM001 on sqlite and pgsql, as php does, and works on the
`mysql:` driver. Out of scope: the `mysqli` and `pgsql_*` procedural APIs, `LOAD DATA LOCAL INFILE`.

## Regression notes: default fetch mode and variadic calls

`$stmt->fetch()` with no argument (and so `foreach ($stmt as $row)`) must return an array, as in
php. The cause of a past regression was in call lowering, not in PDO. `PDO::query()` is declared

```php
public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
```

and the compiler packed the variadic into the first OMITTED parameter's slot, so
`$db->query($sql)` passed the empty pack as `$fetchMode`. Fixed in `LowerFns::defaultFillArgs`:
an omitted fixed parameter keeps its own slot and its own default. Telltale symptom of this
class of bug: `prepare()` + `execute()` + `fetch()` is correct while `query()` is not, because
`prepare()` has no optional-before-variadic parameter.

Four related spread-call bugs are fixed too, with PDO-free regression cases in
`tests/aot/cases/spread_call_defaults.php`:

- `new $cls(...$args)` emitted **no value at all** for the pack, so the constructor received
  the stale last value — the CLASS NAME — as its first argument.
- `new C(...$args)` (static class name) had no spread arm either.
- A spread SHORTER than the callee's arity read past the end of the array instead of taking
  the callee's defaults — a SIGSEGV, not a wrong value.
- A spread into a method on an ERASED receiver filled its tail from whichever class the
  fallback resolution picked, so `$o->__construct(...['C'])` took an unrelated class's
  `= 0`. Each class_id arm now expands the pack against its own signature.

`tests/aot/cases/pdo_sqlite_fetch_default.php` covers the PDO surface (bare `fetch()`,
`fetch($var)`, `foreach`, `setFetchMode`, `ATTR_DEFAULT_FETCH_MODE`, `fetchAll`), and
`pdo_sqlite_fetch_obj.php` the object modes.

## Object fetch modes

`FETCH_OBJ`, `FETCH_CLASS` (with constructor arguments), `FETCH_INTO`, `FETCH_LAZY` and
`fetchObject()` all work and are byte-exact against `php`. They were blocked on the erasure
root — an untyped property is `mixed`, and `new $cls()` never boxed its result — which is
fixed in this tree.

## Tests

`tests/aot/cases/pdo_sqlite_*.php` — thirteen cases, every one graded against the **real `php`
interpreter**, since this host's php has `pdo_sqlite`. No expected output was hand-written.

```bash
bash tests/aot/run.sh -k pdo      # the family
bash tools/difftest.sh            # byte-exact parity vs php
```

| case | covers |
|---|---|
| `pdo_sqlite_basic` | open, `exec`, `query`, `lastInsertId`, multi-statement `exec` |
| `pdo_sqlite_prepare` | `:name` and `?` binding, rebinding, a placeholder inside a literal / comment |
| `pdo_sqlite_types` | INTEGER / REAL / TEXT / BLOB / NULL round trip, NUL bytes, `PARAM_*` |
| `pdo_sqlite_fetch` | every working fetch mode, `ATTR_CASE`, `ATTR_STRINGIFY_FETCHES` |
| `pdo_sqlite_txn` | begin/commit/rollBack, `rowCount`, `columnCount`, `quote` |
| `pdo_sqlite_errors` | all three ERRMODEs, SQLSTATE mapping, `errorInfo`, connect failures |
| `pdo_sqlite_file` | file DSN, persistence across handles, the anonymous temp database |

## Network driver tests

`tests/pdo_net/` runs the `pgsql_*` and `mysql_*` cases against live servers, graded against php's own pdo_pgsql / pdo_mysql on the same
server (a sibling `<case>.expected` replaces the oracle for Manticore-only behaviour). `bash tests/pdo_net/up.sh` starts the servers in docker
(postgres 16, mysql 8.4 plain / TLS / native-password / isolated, mariadb 11), `bash tests/pdo_net/run.sh [-k substr] [--server mysql|mariadb]` runs
them, `down.sh` removes them. A case whose server is unreachable is skipped; a run in which nothing ran fails. The oracle php needs `pdo_pgsql`
and `pdo_mysql`. CI runs the job `pdo-net` on arm64 and amd64 against mysql and mariadb.

## Host requirement

libsqlite3 with `pkg-config sqlite3`, and only to compile a program that uses the sqlite driver; the network drivers need nothing beyond libssl.
See `docs/install.md`.
