<?php
set_error_handler(function ($n, $s) { if (!(error_reporting() & $n)) { return false; } echo "WARN: $s\n"; return true; });
function opts(int $mode, array $o = []): array { return $o + [PDO::ATTR_ERRMODE => $mode]; }
function pdo(int $mode = PDO::ERRMODE_EXCEPTION, array $o = []): PDO { return new PDO((string) getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', opts($mode, $o)); }
function norm(mixed $v): string {
    $s = var_export($v, true);
    $s = preg_replace('/Uptime: \d+  Threads: \d+  Questions: \d+  Slow queries: \d+  Opens: \d+  Flush tables: \d+  Open tables: \d+  Queries per second avg: [\d.]+/', 'STATS', $s);
    $s = preg_replace("/^'\\d+\\.\\d+\\.\\d+(-[A-Za-z0-9.-]+)?'$/", 'V', $s);
    if (preg_match("/^'(mysqlnd|manticore)[^']*'$/", $s)) { return 'CLIENTV'; }
    return $s;
}
$all = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 14, 15, 16, 17, 18, 19, 20, 21, 1000, 1001, 1002, 1003, 1004, 1005, 1012, 9999];
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING, PDO::ERRMODE_EXCEPTION] as $mode) {
    echo "== errmode $mode\n";
    $db = pdo($mode);
    foreach ($all as $a) {
        try { $r = norm($db->getAttribute($a)); echo "get $a: ", $r, "\n"; } catch (PDOException $e) { echo "get $a: ", $e->getMessage(), "\n"; }
    }
    foreach ([2 => 5, 0 => true, 1 => 1, 4 => 1, 6 => 1, 7 => 1, 9999 => 1, 1000 => true, 20 => true, 8 => 2, 3 => $mode, 1004 => true, 1012 => false, 1001 => true, 1002 => 'set @x=1', 1003 => true, 1005 => true, 14 => true, 21 => 2, 12 => true, 5 => 'x', 16 => 'y', 17 => true] as $a => $v) {
        try { $r = norm($db->setAttribute($a, $v)); echo "set $a: ", $r, " ", json_encode($db->errorInfo()), "\n"; } catch (PDOException $e) { echo "set $a: ", $e->getMessage(), "\n"; }
    }
    echo 'version matches select: ';
    $s = $db->query('select version()');
    var_dump($db->getAttribute(PDO::ATTR_SERVER_VERSION) === $s->fetchColumn());
    echo 'client version string: ', is_string($db->getAttribute(PDO::ATTR_CLIENT_VERSION)) ? 'string' : 'other', "\n";
}
echo "== attributes that change behaviour\n";
$db = pdo();
$db->exec('create temporary table t(a int, b varchar(5))');
$db->exec("insert into t values (1, 'x'), (2, null), (3, '')");
foreach ([PDO::ATTR_CASE => PDO::CASE_UPPER, PDO::ATTR_ORACLE_NULLS => PDO::NULL_TO_STRING, PDO::ATTR_STRINGIFY_FETCHES => true, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM] as $a => $v) {
    $db->setAttribute($a, $v);
    $s = $db->query('select a, b from t order by a');
    echo $a, ': ', json_encode($s->fetchAll()), ' ', json_encode($db->getAttribute($a)), "\n";
    $db->setAttribute($a, $a === PDO::ATTR_DEFAULT_FETCH_MODE ? PDO::FETCH_BOTH : 0);
}
$db->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_EMPTY_STRING);
$s = $db->query('select a, b from t order by a');
echo 'NULL_EMPTY_STRING: ', json_encode($s->fetchAll(PDO::FETCH_NUM)), "\n";
$db->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
echo "== server info, connection status, driver name\n";
echo norm($db->getAttribute(PDO::ATTR_SERVER_INFO)), "\n";
echo norm($db->getAttribute(PDO::ATTR_CONNECTION_STATUS)), "\n";
echo norm($db->getAttribute(PDO::ATTR_DRIVER_NAME)), "\n";
echo "== ATTR_EMULATE_PREPARES\n";
foreach ([true, false] as $e) {
    $d = pdo(PDO::ERRMODE_EXCEPTION, [PDO::ATTR_EMULATE_PREPARES => $e]);
    echo 'ctor ', var_export($e, true), ': ', var_export($d->getAttribute(PDO::ATTR_EMULATE_PREPARES), true), "\n";
}
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$s = $db->prepare('select ? as x');
$s->execute(['é']);
echo json_encode($s->fetchAll(PDO::FETCH_ASSOC)), "\n";
echo "== MYSQL_ATTR_USE_BUFFERED_QUERY\n";
echo 'default: ', var_export($db->getAttribute(1000), true), "\n";
echo 'set true: ', var_export($db->setAttribute(1000, true), true), "\n";
echo "== MYSQL_ATTR_FOUND_ROWS\n";
foreach ([false, true] as $f) {
    $d = pdo(PDO::ERRMODE_EXCEPTION, [1004 => $f]);
    $d->exec('create temporary table t(a int, b int)');
    $d->exec('insert into t values (1, 1), (2, 2)');
    $u = $d->prepare('update t set b = ? where a <= 2');
    $u->execute([1]);
    echo 'found_rows ', var_export($f, true), ': exec ', $d->exec('update t set b = 1'), ' stmt ', $u->rowCount(), "\n";
}
echo "== MYSQL_ATTR_MULTI_STATEMENTS\n";
foreach ([true, false] as $m) {
    $d = pdo(PDO::ERRMODE_EXCEPTION, [1012 => $m]);
    $d->exec('create temporary table t(a int)');
    foreach (['insert into t values (1); insert into t values (2)', 'set @a = 1; set @b = 2'] as $q) {
        try { $n = $d->exec($q); echo 'multi ', var_export($m, true), ' exec: ', var_export($n, true), "\n"; }
        catch (PDOException $e) { echo 'multi ', var_export($m, true), ' exec: ', $e->getMessage(), "\n"; }
    }
    $s = $d->query('select count(*) from t');
    echo 'rows: ', $s->fetchColumn(), "\n";
}
echo "== MYSQL_ATTR_INIT_COMMAND\n";
$d = pdo(PDO::ERRMODE_EXCEPTION, [1002 => "SET @mc_init = 'ran', SESSION sql_mode = ''"]);
$s = $d->query('select @mc_init, @@sql_mode');
echo json_encode($s->fetch(PDO::FETCH_NUM)), "\n";
echo "== MYSQL_ATTR_DIRECT_QUERY / ATTR_PERSISTENT / ATTR_PREFETCH\n";
$d = pdo(PDO::ERRMODE_EXCEPTION, [20 => false, PDO::ATTR_PERSISTENT => false, PDO::ATTR_PREFETCH => 1]);
echo 'direct ', var_export($d->getAttribute(20), true), ' persistent ', var_export($d->getAttribute(PDO::ATTR_PERSISTENT), true), "\n";
echo "== ATTR_TIMEOUT\n";
$d = pdo(PDO::ERRMODE_EXCEPTION, [PDO::ATTR_TIMEOUT => 7]);
try { echo 'get: ', json_encode($d->getAttribute(PDO::ATTR_TIMEOUT)), "\n"; } catch (PDOException $e) { echo 'get: ', $e->getMessage(), "\n"; }
echo "== ATTR_ERRMODE through the constructor and setAttribute\n";
$d = pdo(PDO::ERRMODE_WARNING);
echo $d->getAttribute(PDO::ATTR_ERRMODE), "\n";
$d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
echo $d->getAttribute(PDO::ATTR_ERRMODE), ' ', var_export($d->exec('selec'), true), "\n";
echo "== quote\n";
$db = pdo();
foreach (["a'b", "a\\b", "a\\'b", '', "x\0y", "é", "''", "\xff\xfe", "ok", "a\nb\rc\x1a\"d"] as $x) { echo json_encode($x), ' => ', json_encode($db->quote($x)), "\n"; }
foreach ([PDO::PARAM_LOB, PDO::PARAM_INT, PDO::PARAM_STR, PDO::PARAM_BOOL, PDO::PARAM_NULL, PDO::PARAM_STR | PDO::PARAM_STR_NATL] as $t) { echo "type $t => ", json_encode($db->quote("a\\b'c", $t)), "\n"; }
$db->exec("set sql_mode = 'NO_BACKSLASH_ESCAPES'");
foreach (["a'b", "a\\b\\'", 'plain'] as $x) { echo json_encode($x), ' => ', json_encode($db->quote($x)), "\n"; }
$q = $db->quote("it's\\");
$s = $db->query('select ' . $q);
echo 'roundtrip ', json_encode($s->fetchColumn()), "\n";
$db->exec("set sql_mode = ''");
$q = $db->quote("it's\\");
$s = $db->query('select ' . $q);
echo 'roundtrip ', json_encode($s->fetchColumn()), "\n";
echo "== ATTR_DEFAULT_STR_PARAM\n";
$db = pdo();
echo $db->getAttribute(PDO::ATTR_DEFAULT_STR_PARAM), ' ', json_encode($db->quote('x')), "\n";
var_dump($db->setAttribute(PDO::ATTR_DEFAULT_STR_PARAM, PDO::PARAM_STR_NATL));
echo $db->getAttribute(PDO::ATTR_DEFAULT_STR_PARAM), ' ', json_encode($db->quote('x')), ' ', json_encode($db->quote('x', PDO::PARAM_STR | PDO::PARAM_STR_CHAR)), ' ', json_encode($db->quote('x', PDO::PARAM_LOB)), "\n";
$s = $db->prepare('select ? as v');
$s->execute(['é']);
echo json_encode($s->fetchAll(PDO::FETCH_ASSOC)), "\n";
$d4 = pdo(PDO::ERRMODE_EXCEPTION, [PDO::ATTR_DEFAULT_STR_PARAM => PDO::PARAM_STR_NATL, PDO::ATTR_FETCH_TABLE_NAMES => true]);
echo $d4->getAttribute(21), ' ', var_export($d4->getAttribute(14), true), "\n";
echo "== ATTR_PERSISTENT\n";
$d5 = pdo(PDO::ERRMODE_EXCEPTION, [PDO::ATTR_PERSISTENT => true]);
echo var_export($d5->getAttribute(PDO::ATTR_PERSISTENT), true), ' ', var_export($d5->setAttribute(PDO::ATTR_PERSISTENT, false), true), ' ', var_export($d5->getAttribute(PDO::ATTR_PERSISTENT), true), "\n";
