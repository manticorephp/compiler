# `ext/mysqli`

The full php.net mysqli surface, in pure PHP over the MySQL wire protocol (the same socket layer as `pdo_mysql`): no
libmysqlclient, no mysqlnd. Works against MySQL 8.x and MariaDB 11. The program output is byte-identical to php with
mysqli loaded; that is checked per server by `tests/mysqli_net` (live php as the oracle).

```php
$db = new mysqli('127.0.0.1', 'u', 'pw', 'app');
$st = $db->prepare('SELECT id, name FROM t WHERE id > ?');
$min = 10;
$st->bind_param('i', $min);
$st->execute();
$st->bind_result($id, $name);
while ($st->fetch()) {
    echo $id, ' ', $name, "\n";
}
foreach ($db->query('SELECT id, name FROM t') as $row) {   // mysqli_result is IteratorAggregate
    echo $row['id'], "\n";
}
```

Classes `mysqli`, `mysqli_stmt`, `mysqli_result`, `mysqli_driver`, `mysqli_warning`, `mysqli_sql_exception`, every
procedural `mysqli_*` function and every `MYSQLI_*` constant exist with the Zend signature. A program that never
mentions `mysqli` links none of it.

## Report modes

`mysqli_report()` / `mysqli_driver::$report_mode` are real and per process.

| Mode | Behaviour |
|---|---|
| default (`ERROR \| STRICT`, php >= 8.1) | a failed call throws `mysqli_sql_exception` (`getCode()` = errno, `getSqlState()`) |
| `MYSQLI_REPORT_OFF` | the call returns `false` / `null` as php does; `errno`, `error`, `sqlstate`, `error_list` are set; nothing is thrown |
| `MYSQLI_REPORT_ERROR` alone | php warns and carries on. Manticore has no warnings: the call returns `false` and carries on, nothing is printed |
| `MYSQLI_REPORT_STRICT` | throws, as the default |
| `MYSQLI_REPORT_INDEX` | php warns when a query used no (or a bad) index. Manticore does not inspect the server flags: no output |
| `MYSQLI_REPORT_ALL` | 255 (all bits); behaves as STRICT |

A connect failure is recorded process-wide (`mysqli_connect_errno()`, `mysqli_connect_error()`) and thrown iff STRICT is
set. `refresh()` and `stat()` never throw, as in php.

## Connecting

- `new mysqli($host, $user, $pw, $db, $port, $socket)`, `mysqli_connect()`, `mysqli_init()` + `real_connect()`,
  `options()`, `ssl_set()`, `change_user()`, `p:` persistent prefix (accepted; connections are not pooled).
- **Unix socket**: host `localhost` (or none) is the unix socket, taken from the `$socket` argument, then
  `mysqli.default_socket`, then the first of `/var/run/mysqld/mysqld.sock`, `/tmp/mysql.sock`,
  `/var/lib/mysql/mysql.sock` that exists; `host_info` reads `Localhost via UNIX socket`. When none exists the
  connection falls back to TCP `127.0.0.1` (php fails with 2002 there). Any other host is TCP.
- TLS: `MYSQLI_CLIENT_SSL`, `ssl_set()`, `MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT`.
- `mysqli.default_host`, `default_user`, `default_pw`, `default_port`, `default_socket` ini values are honoured.
- Native types: text-protocol results are strings, prepared results are ints/floats, unless
  `MYSQLI_OPT_INT_AND_FLOAT_NATIVE` (as mysqlnd).

## Async

Two layers.

1. **Fiber async.** Every blocking call (`connect`, `query`, `fetch`, `execute`, `multi_query`, ...) parks the fiber
   in an `Async` task instead of blocking the loop. Two tasks on two connections overlap:

   ```php
   Async\spawn(fn() => (new mysqli(...))->query('SELECT SLEEP(1)'));
   Async\spawn(fn() => (new mysqli(...))->query('SELECT SLEEP(1)'));   // ~1 s total, not 2
   ```

2. **Zend's async API**, over the same wire: `query($sql, MYSQLI_ASYNC)` sends and returns `true`;
   `mysqli::poll($read, $error, $reject, $sec, $usec)` / `mysqli_poll()` waits for readiness and rewrites the arrays
   by reference; `reap_async_query()` / `mysqli_reap_async_query()` reads the reply. Any other command on a link with a
   reply pending is 2014 "Commands out of sync". poll and reap's php warnings are silent.
   A reply can be reaped only by the fiber that sent it (2014 otherwise; the owner reaps fine). This is a Manticore rule.

## Stubs

These exist with the Zend signature and the most harmless Zend-like result. Each is pinned by a golden test.

| Symbol | Behaviour |
|---|---|
| `mysqli::debug()`, `mysqli_debug()` | `true`, no effect |
| `mysqli::dump_debug_info()`, `mysqli_dump_debug_info()` | `true`, no effect |
| `mysqli_get_client_stats()`, `mysqli::get_connection_stats()`, `mysqli_get_connection_stats()` | the mysqlnd key set (163 keys), every value the string `"0"`; counters are not metered |
| `mysqli_get_links_stats()` | the three-key array, zeroed ints |

`mysqli_driver::embedded_server_start()` / `embedded_server_end()` were
removed in php 8.5; they do not exist here either.

`kill`, `refresh`, `stat`, `change_user`, `get_charset`, `get_warnings`, `send_long_data`, `thread_safe` are real
(`COM_PROCESS_KILL`, `COM_REFRESH`, `COM_STATISTICS`, `COM_CHANGE_USER`, `SHOW WARNINGS`, `COM_STMT_SEND_LONG_DATA`).
MySQL 8.4 answers ERR 1047 to `COM_REFRESH` / `COM_PROCESS_KILL`; the call reports it as php does.

## Known gaps and divergences

- **Warnings are not printed.** Where php warns and carries on, Manticore stays silent (see report modes).
- `mysqli_report(MYSQLI_REPORT_INDEX)` does nothing observable.
- `var_dump` object handle numbers (`#N`) and `spl_object_id` differ from php (#457).
- `var_dump` and `print_r` match php through `__debugInfo`. php lists no property of a mysqli object anywhere else;
  here `get_object_vars()` lists the virtual hooked properties (with their hook values), `foreach` lists them and the
  private `$__mc` (#487), and `(array)` lists `$__mc` alone.
- `ini_get('mysqli.default_socket')` is `''` (php: its compiled-in path).
- Statistics are not metered (see stubs).
- Closing a link with an async reply pending sends `KILL QUERY` on a side connection; mysqlnd drops the socket.
- php hangs or desyncs when `reap_async_query()` is called on a link busy with a non-async result; here it answers
  `false` plus the last error.
- Connections are not pooled for `p:` hosts.

### Internals visible to introspection (accepted)

The surface audit (`tools/mysqli_surface_audit.php`, golden `tests/mysqli_net/surface.json`) reports seven extras on
purpose; they are not part of Zend's surface but cannot be hidden:

- `mysqli::__destruct`, `mysqli_result::__destruct`, `mysqli_stmt::__destruct`, `mysqli_warning::__clone`
- the private properties `mysqli_warning::$__i`, `$__msg`, `$__no`

The audit skips names starting `__mc` and `__debugInfo`, but `get_class_methods()`, `method_exists()` and reflection
see them too. The full list of internal members:

| Class | Methods | Properties |
|---|---|---|
| `mysqli` | `__destruct`, `__debugInfo`, `__mcConn`, `__mcBeginTx`, `__mcEndTx`, `__mcSavepoint`, `__mcExecuteQuery`, `__mcKill`, `__mcQuery` | private `$__mc` |
| `mysqli_result` | `__destruct`, `__debugInfo`, `__mcRs`, `__mcDataSeek`, `__mcFetchAll`, `__mcFetchArray`, `__mcFetchColumn`, `__mcFetchObject`, `__mcFieldDirect`, `__mcFieldSeek` | private `$__mc` |
| `mysqli_stmt` | `__destruct`, `__debugInfo`, `__mcSt`, `__mcAttrGet`, `__mcAttrSet`, `__mcBindParam`, `__mcDataSeek`, `__mcDetach`, `__mcExecute`, `__mcSendLongData` | private `$__mc` |
| `mysqli_warning` | `__clone`, `__debugInfo`, static `__mcNew` | private `$__msg`, `$__no`, `$__i` |
| `mysqli_driver` | `__debugInfo` | |
| `mysqli_sql_exception` | static `__mcNew` | |

The private `$__mc` shows in an `(array)` cast and in `ReflectionClass::getProperties()`. Reflection also lists the
declared properties in source order, which is not Zend's registration order (`var_dump` / `print_r` use Zend's, through
`__debugInfo`). A subclass of `mysqli` that declares its own `__destruct` without calling `parent::__destruct()` keeps
the link open until the connection state itself is freed (Zend closes it regardless).

## Tests

```bash
bash tests/pdo_net/up.sh                                  # docker: mc-my (mysql :33060), mc-maria (mariadb :33061)
bash tests/mysqli_net/run.sh --server mysql [-k substr] [--shard i/n]
bash tests/mysqli_net/run.sh --server mariadb
MYSQLI_SOCKET=/path/to/mysqld.sock bash tests/mysqli_net/run.sh -k socket
bash tests/aot/run.sh -k mysqli                           # server-less: surface, stubs, gate rule
```

Each case runs under php and the compiled binary against the same live server; stdout and exit status must agree.
A case with a `.expected` file (the `Async\` superset) is compared with that file instead. A case that needs an env var
(`// requires-env: MYSQLI_SOCKET`) is skipped without it.
