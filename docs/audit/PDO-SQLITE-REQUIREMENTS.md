# pdo_sqlite requirements

Derived by `tools/audit/requirements.php` from doctrine/dbal + doctrine/orm
as installed in symfony-demo. Do not hand-edit — regenerate.

## Two FFI ceilings that shape the design

1. **No callbacks.** Nothing can take a PHP function's address, so
   `sqlite3_exec()` and `sqlite3_create_function()` are unreachable. The
   `sqlite3_prepare_v2` / `step` / `column_*` / `finalize` route is the
   only viable one — which is what PDO wants anyway.
2. **No struct-by-value.** Args and returns are i64/ptr/double/i1 only.
   sqlite3 passes handles as opaque pointers, so this does not bite, but
   it rules out any C API that returns a struct.

Also: the extension manifest's `"static"` key is never read (Main.php
reads only `link`), so an extension links dynamically today — at odds
with the fully-static-binary goal, and worth closing in the same epic.

And `PDO`/`PDOStatement` must NOT live in `src/Runtime`: a compiled
library's `.sig` carries functions only, so a class declared there is
never registered by a user program. `prelude/resource.php` documents the
same trap. Extension glue compiles INTO the application module, so
`ext/sqlite3/` is the place classes can safely live.

## PDO surface referenced

### Class constants and static members — 37

| member | first site |
|---|---|
| `PDO::ATTR_` | vendor/symfony/var-dumper/Caster/PdoCaster.php:77 |
| `PDO::ATTR_CASE` | vendor/doctrine/dbal/src/Portability/Driver.php:58 |
| `PDO::ATTR_DRIVER_NAME` | vendor/doctrine/dbal/src/Driver/PDO/Connection.php:87 |
| `PDO::ATTR_ERRMODE` | vendor/doctrine/dbal/src/Driver/PDO/Connection.php:21 |
| `PDO::ATTR_PERSISTENT` | vendor/doctrine/dbal/src/Driver/PDO/MySQL/Driver.php:32 |
| `PDO::ATTR_SERVER_VERSION` | vendor/doctrine/dbal/src/Driver/PDO/Connection.php:39 |
| `PDO::CASE_LOWER` | vendor/symfony/var-dumper/Caster/PdoCaster.php:29 |
| `PDO::CASE_NATURAL` | vendor/symfony/var-dumper/Caster/PdoCaster.php:30 |
| `PDO::CASE_UPPER` | vendor/symfony/var-dumper/Caster/PdoCaster.php:31 |
| `PDO::ERRMODE_EXCEPTION` | vendor/doctrine/dbal/src/Driver/PDO/Connection.php:21 |
| `PDO::ERRMODE_SILENT` | vendor/symfony/var-dumper/Caster/PdoCaster.php:34 |
| `PDO::ERRMODE_WARNING` | vendor/symfony/var-dumper/Caster/PdoCaster.php:35 |
| `PDO::FETCH_` | vendor/doctrine/dbal/src/Driver/PDO/Result.php:102 |
| `PDO::FETCH_ASSOC` | vendor/doctrine/dbal/src/Driver/PDO/Result.php:28 |
| `PDO::FETCH_BOTH` | vendor/symfony/var-dumper/Caster/PdoCaster.php:57 |
| `PDO::FETCH_COLUMN` | vendor/doctrine/dbal/src/Driver/PDO/Result.php:33 |
| `PDO::FETCH_LAZY` | vendor/symfony/var-dumper/Caster/PdoCaster.php:58 |
| `PDO::FETCH_NUM` | vendor/doctrine/dbal/src/Driver/PDO/Result.php:23 |
| `PDO::FETCH_OBJ` | vendor/symfony/var-dumper/Caster/PdoCaster.php:60 |
| `PDO::NULL_EMPTY_STRING` | vendor/symfony/var-dumper/Caster/PdoCaster.php:46 |
| `PDO::NULL_NATURAL` | vendor/symfony/var-dumper/Caster/PdoCaster.php:45 |
| `PDO::NULL_TO_STRING` | vendor/symfony/var-dumper/Caster/PdoCaster.php:47 |
| `PDO::PARAM_` | vendor/doctrine/dbal/src/Driver/PDO/Statement.php:66 |
| `PDO::PARAM_BOOL` | vendor/doctrine/dbal/src/Driver/PDO/Statement.php:77 |
| `PDO::PARAM_INT` | vendor/doctrine/dbal/src/Driver/PDO/Statement.php:72 |
| `PDO::PARAM_LOB` | vendor/doctrine/dbal/src/Driver/PDO/Statement.php:76 |
| `PDO::PARAM_NULL` | vendor/doctrine/dbal/src/Driver/PDO/Statement.php:71 |
| `PDO::PARAM_STR` | vendor/doctrine/dbal/src/Driver/PDO/Statement.php:74 |
| `PDO::PGSQL_ATTR_DISABLE_PREPARES` | vendor/doctrine/dbal/src/Driver/PDO/PgSQL/Driver.php:60 |
| `PDO::SQLSRV_ENCODING_BINARY` | vendor/doctrine/dbal/src/Driver/PDO/SQLSrv/Statement.php:29 |
| `PDO::SQLSRV_ENCODING_SYSTEM` | vendor/doctrine/dbal/src/Driver/PDO/SQLSrv/Statement.php:38 |
| `PDO::commit` | vendor/symfony/http-foundation/Session/Storage/Handler/PdoSessionHandler.php:567 |
| `PDO::connect` | vendor/doctrine/dbal/src/Driver/PDO/PDOConnect.php:28 |
| `PDO::inTransaction` | vendor/symfony/http-foundation/Session/Storage/Handler/PdoSessionHandler.php:568 |
| `PDO::lastInsertId` | vendor/doctrine/dbal/src/Driver/PDO/Connection.php:79 |
| `PDO::rollback` | vendor/symfony/http-foundation/Session/Storage/Handler/PdoSessionHandler.php:568 |
| `PDOException::new` | vendor/doctrine/dbal/src/Driver/PDO/SQLSrv/Driver.php:65 |

### Methods called on a connection/statement handle — 27

Heuristic (receiver named `$pdo`/`$stmt`/`$conn`/…), so treat as a
floor rather than the exact set.

| method | first site |
|---|---|
| `beginTransaction()` | vendor/doctrine/dbal/src/Connection.php:1058 |
| `bindValue()` | vendor/doctrine/dbal/src/Connection.php:1324 |
| `commit()` | vendor/doctrine/dbal/src/Connection.php:1083 |
| `convertToDatabaseValue()` | vendor/doctrine/orm/src/Tools/Pagination/Paginator.php:276 |
| `createQueryBuilder()` | vendor/doctrine/dbal/src/Query/QueryBuilder.php:192 |
| `enableExceptions()` | vendor/doctrine/dbal/src/Driver/SQLite3/Driver.php:44 |
| `ensureConnectedToPrimary()` | vendor/doctrine/dbal/src/Connections/PrimaryReadReplicaConnection.php:57 |
| `exec()` | vendor/doctrine/dbal/src/Connection.php:909 |
| `execute()` | vendor/doctrine/dbal/src/Connection.php:802 |
| `executeQuery()` | vendor/doctrine/dbal/src/Connections/PrimaryReadReplicaConnection.php:41 |
| `executeStatement()` | vendor/doctrine/dbal/src/Tools/Console/Command/RunSqlCommand.php:117 |
| `fetchAllAssociative()` | vendor/doctrine/dbal/src/Tools/Console/Command/RunSqlCommand.php:104 |
| `fetchOne()` | vendor/doctrine/dbal/src/Platforms/MySQL/MySQLMetadataProvider.php:68 |
| `getConfiguration()` | vendor/doctrine/orm/src/Tools/SchemaTool.php:1055 |
| `getDatabasePlatform()` | vendor/doctrine/dbal/src/Schema/DefaultSchemaManagerFactory.php:18 |
| `getEventManager()` | vendor/doctrine/orm/src/EntityManager.php:124 |
| `getNativeConnection()` | vendor/doctrine/dbal/src/Portability/Driver.php:49 |
| `isTransactionActive()` | vendor/doctrine/orm/src/UnitOfWork.php:450 |
| `prepare()` | vendor/doctrine/dbal/src/Connection.php:764 |
| `query()` | vendor/doctrine/dbal/src/Connection.php:804 |
| `quote()` | vendor/doctrine/orm/src/Query/Filter/SQLFilter.php:135 |
| `real_connect()` | vendor/doctrine/dbal/src/Driver/Mysqli/Driver.php:44 |
| `result_metadata()` | vendor/doctrine/dbal/src/Driver/Mysqli/Result.php:53 |
| `rollBack()` | vendor/doctrine/dbal/src/Connection.php:1142 |
| `setAttribute()` | vendor/doctrine/dbal/src/Driver/PDO/Connection.php:21 |
| `set_charset()` | vendor/doctrine/dbal/src/Driver/Mysqli/Initializer/Charset.php:21 |
| `ssl_set()` | vendor/doctrine/dbal/src/Driver/Mysqli/Initializer/Secure.php:25 |

### sqlite mentions inside dbal — 19 distinct tokens

| token | occurrences |
|---|---|
| `sqlite` | 52 |
| `sqlite3` | 21 |
| `sqlite_master` | 14 |
| `sqliteplatform` | 13 |
| `sqlitemetadataprovider` | 6 |
| `sqliteschemamanager` | 4 |
| `sqlite3result` | 3 |
| `sqlitekeywords` | 3 |
| `sqlite_sequence` | 3 |
| `sqlite3_assoc` | 2 |
| `sqlite3_num` | 2 |
| `sqlite3stmt` | 2 |
| `sqlite3_blob` | 2 |
| `sqlite3_integer` | 2 |
| `sqlite3_null` | 2 |
| `sqlite3_text` | 2 |
| `sqlite_` | 2 |
| `sqlitemissingforeignkeyconstraintreferencedcolumns` | 2 |
| `sqlite_temp_master` | 2 |
