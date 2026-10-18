<?php
// servers: mariadb
// MariaDB specifics against php: RETURNING through query/prepare (native and emulated), JSON as LONGTEXT-like BLOB, UUID / INET6 columns, error texts, sql_mode.
$dsn = getenv('PDO_MY_DSN');
foreach ([[PDO::ATTR_EMULATE_PREPARES => true], [PDO::ATTR_EMULATE_PREPARES => false]] as $opt) {
$db = new PDO($dsn, 'u', 'pw', $opt + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo "== emulate ", json_encode($opt), "\n";
echo $db->getAttribute(PDO::ATTR_SERVER_VERSION), "|", $db->getAttribute(PDO::ATTR_CLIENT_VERSION) !== '' ? 'c' : '', "\n";
echo $db->getAttribute(PDO::ATTR_CONNECTION_STATUS), "\n";
$db->exec('drop temporary table if exists t');
$db->exec('create temporary table t (id int auto_increment primary key, v varchar(20), j json, u uuid, i6 inet6, d decimal(10,2), b bit(5), e enum("a","b"), s set("x","y"), ts timestamp null, dt datetime(3) null, y year, tx text, bl blob)');
$q = function ($sql, $mode = null) use ($db) {
  echo "-- $sql\n";
  try {
    $st = $db->query($sql);
    echo "cols=", $st->columnCount(), " rc=", $st->rowCount(), "\n";
    for ($i = 0; $i < $st->columnCount(); $i++) { $m = $st->getColumnMeta($i); echo json_encode([$m['name'], $m['native_type'] ?? null, $m['pdo_type'], $m['len'], $m['precision'], $m['flags']]), "\n"; }
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) echo json_encode($r), "\n";
  } catch (PDOException $e) { echo get_class($e), ' ', json_encode($e->errorInfo), "\n"; }
};
$q("insert into t (v, j, u, i6, d, b, e, s, ts, dt, y, tx, bl) values ('a', '{\"a\": 1}', '123e4567-e89b-12d3-a456-426614174000', '::1', 1.5, b'101', 'a', 'x,y', '2020-01-02 03:04:05', '2020-01-02 03:04:05.123', 2020, 'txt', 'bl') returning id, v, j, u, i6");
$q("select * from t");
$q("insert into t (v) values ('r1'),('r2') returning id");
$q("delete from t where v like 'r%' returning id, v");
$st = $db->prepare("insert into t (v) values (?) returning id, v");
$st->execute(['p1']); echo "prep rc=", $st->rowCount(), " cols=", $st->columnCount(), " ", json_encode($st->fetchAll(PDO::FETCH_ASSOC)), " last=", $db->lastInsertId(), "\n";
$st = $db->prepare("update t set v='z' where v=?");
$st->execute(['p1']); echo "upd rc=", $st->rowCount(), json_encode($st->fetchAll(PDO::FETCH_NUM)), "\n";
$q("select @@sql_mode, @@version_comment is not null, @@character_set_client, @@collation_connection, @@autocommit, @@transaction_isolation");
$q("show status like 'Threads_connected'");
$q("select * from nonexistent");
$q("selct 1");
$q("insert into t (id) values (1),(1)");
$q("select 1 from t where v = 'x' group by v having count(*) > 'a'");
$db->exec("set sql_mode=''"); $q("insert into t (e) values ('zz')");
$q("select sleep(0), benchmark(1,1), @@max_allowed_packet");
$q("show variables like 'version%'");
$q("select name from mysql.proc limit 1");
$q("create table if not exists t2 (a int)"); 
$q("drop table if exists t2");
try { $db->exec("kill 99999"); } catch (PDOException $e) { echo json_encode($e->errorInfo), "\n"; }
}
