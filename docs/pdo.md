# `ext/pdo` + `pdo_sqlite`

PHP Data Objects, with SQLite behind it. `new PDO('sqlite::memory:')` works, and so do
prepared statements, the array fetch modes, transactions, and php's three error modes.

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

Two prelude files, both global-namespace, both demand-gated on a program mentioning `PDO` /
`PDOStatement` / `PDOException` / `PDORow`:

| file | what |
|---|---|
| `prelude/pdo.php` | the driver-agnostic facade: `PDO`, `PDOStatement`, `PDOException`, `PDORow`, every `PDO::` constant, the fetch modes, the DSN split, and the `__McPdoDrv` / `__McPdoDrvStmt` seam |
| `prelude/pdo_sqlite.php` | the SQLite driver: ~30 libsqlite3 FFI bindings and the two classes that satisfy the seam |

**The prelude and not `src/Runtime/Stdlib`** for the same reason as ext/curl: the stdlib
`.o.sig` carries FUNCTIONS ONLY. `new PDO(...)` hands back an object and `PDO::FETCH_ASSOC` is
a class constant, and a class declared in the stdlib is never registered by the program holding
one of its instances — `instanceof` reads false and its properties come back as raw bits.

**Linking.** `#[\Ffi\Library('sqlite3')]` is the whole story: the emitter collects the library
off every wrapper it emits and `generic_link_flags()` resolves it through
`pkg-config --libs sqlite3`. A binary that never mentions PDO never emits a wrapper and never
links `-lsqlite3`.

## The driver seam

`PDO` and `PDOStatement` hold no database knowledge. They delegate to two interfaces, and
everything database-shaped lives behind them. A future `mysql:` / `pgsql:` driver is the
opposite shape — a socket with `pack`/`unpack`, no FFI at all — and implements the same two
interfaces without touching the facade.

SQLite deserves saying plainly: **it is not a server.** There is no wire protocol and nothing to
unpack; `sqlite3_step` is a `pread` on a local file. That is why this driver is FFI and the
network drivers will not be.

Adding a driver means: implement `__McPdoDrv` + `__McPdoDrvStmt`, add one arm to the scheme
`match` in `PDO::__construct`, and split the prelude gate in `Main.php` the way `curl_multi`
does. The sqlite driver currently rides the facade unconditionally, because a DSN scheme is a
runtime **string** and `PreludeDemand` is token-based — nothing at compile time can tell which
driver `new PDO($dsn)` will open.

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

The API is synchronous, like Zend's. The scheduler shows up in one place: **`SQLITE_BUSY`**.

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
- **`bindParam()` binds by value at bind time.** php re-reads the caller's variable at
  `execute()`. A stored by-reference binding needs a zval reference and an array here carries no
  is_ref bit. Bind-then-execute — the overwhelmingly common shape — is identical; only a program
  that mutates the variable *between* `bindParam()` and `execute()` sees a difference. Documented
  rather than refused, because throwing would break every Doctrine-shaped caller outright.
- **`bindColumn()` / `PDO::FETCH_BOUND` throw**, for the same missing primitive — but there the
  write-back *is* the whole feature, so there is no half of it worth approximating.
- **`ATTR_PERSISTENT` is accepted and ignored.** One process, no pool.
- **`getAvailableDrivers()` returns what this binary linked** — `['sqlite']` — where php lists
  every driver its build has.

`getColumnMeta()['table']` is **not** a divergence: `sqlite3_column_table_name()` is bound
`#[\Ffi\Weak]` and called only when `sqlite3_compileoption_used('ENABLE_COLUMN_METADATA')` says
this libsqlite3 has it — sqlite's own answer, so no dlsym and no OS branch. A build without the
option omits the key, which is also what php does for an expression column.

Cursor orientation is not one either: `$cursorOrientation` and `$cursorOffset` are accepted and
ignored, exactly as pdo_sqlite does — a sqlite statement is forward-only and php returns the
next row whatever is asked for (measured: `ORI_PRIOR` / `FIRST` / `LAST` / `ABS` / `REL` all
behave as `ORI_NEXT`). Throwing was stricter than the oracle.

## Not implemented, each with a named throw

`PDO::FETCH_BOUND` and `bindColumn()` — both need a stored by-reference binding, the same
missing primitive `bindParam()` documents above. `nextRowset()` reports IM001, as php's sqlite
driver does.

## The default-fetch bug, and what it turned out to be

`$stmt->fetch()` with no argument — and therefore `foreach ($stmt as $row)` — used to hand
back an object where php hands back an array, and `count()` on it answered a pointer.

It was not a PDO bug and it was not about `$fetchMode` at all. `PDO::query()` is declared

```php
public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
```

and the compiler's call lowering **packed the variadic into the first OMITTED parameter's
slot**: `$db->query($sql)` passed the empty pack as `$fetchMode`. So `$fetchMode !== null`
held, `setFetchMode()` ran with an array POINTER, and every later `fetch()` masked that
pointer's low nibble into a fetch mode. Fixed in `LowerFns::defaultFillArgs` — an omitted
fixed parameter keeps its own slot and its own default.

The discriminator that found it in minutes, after an earlier session spent an hour on toy
repros: **`prepare()` + `execute()` + `fetch()` was always correct, only `query()` was not.**
`prepare()` has no optional-before-variadic parameter.

Four further compiler bugs in the same family surfaced from PDO's own `FETCH_CLASS` path and
are fixed with it — all four have PDO-free regression cases in
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

One gap remains, and it is **not** PDO's: `json_encode()` of any object (a plain `stdClass`
included) renders `{}`, because the stdlib's json encoder walks the operand's STATIC class
from a separate module. `get_object_vars()` on the same object is correct, so
`json_encode(get_object_vars($row))` is the workaround until the module-boundary fix lands.

## Tests

`tests/aot/cases/pdo_sqlite_*.php` — seven cases, every one graded against the **real `php`
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

## Host requirement

libsqlite3 with `pkg-config sqlite3`, and only to compile a program that mentions `PDO`.
See `docs/install.md`.
