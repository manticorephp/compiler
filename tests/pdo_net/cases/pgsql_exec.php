<?php
$db = new PDO(getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
var_dump($db->exec('create temp table t(a int not null primary key, b text)'));
var_dump($db->exec("insert into t values (1,'x'),(2,'y'),(3,null)"));
var_dump($db->exec("update t set b='z' where a>1"));
var_dump($db->exec('select 1'));
var_dump($db->exec('delete from t where a=99'));
$st = $db->query('select 1, 2');
var_dump($st->fetch(PDO::FETCH_NUM), $st->rowCount(), $st->columnCount());
var_dump($db->query('select a, b from t order by a')->fetchAll(PDO::FETCH_ASSOC));
var_dump($db->query("select 1::int2 a, 2::int8 b, true c, false d, 1.5 e, 'x' f, null g, 5::oid h, '1'::numeric i")->fetch(PDO::FETCH_NUM));
var_dump($db->query('select a from t where a=99')->fetchAll());
var_dump($db->query('select g from generate_series(1,3) g')->rowCount());
foreach (['selec 1', 'select nope from t', "select 'x'::int", 'select 1/0', "select repeat('a',70) || nope",
          "select 1\n,\n  from", 'insert into t(b) values (1)', "insert into t values (1,'dup')"] as $q) {
    try { $db->exec($q); } catch (PDOException $e) { echo json_encode($e->getMessage()), ' ', json_encode($e->errorInfo), "\n"; } // getCode(): SQLSTATE string in php, int 7 here (docs/pdo.md)
}
var_dump($db->exec("create temp table u(a serial)"), $db->exec("insert into u default values"), $db->lastInsertId('u_a_seq'), $db->lastInsertId());
var_dump($db->quote("a'b\\c"), $db->getAttribute(PDO::ATTR_SERVER_VERSION) === $db->query('show server_version')->fetchColumn());
$db->beginTransaction(); var_dump($db->inTransaction()); $db->exec("insert into t values (10,'q')"); $db->rollBack();
var_dump($db->inTransaction(), $db->query('select count(*) from t')->fetchColumn());
var_dump($db->exec("select 1; insert into t values (20,'m'); update t set b='n' where a=20"));
$pad = str_repeat('x', 40);
foreach ([
    "select $pad, $pad, $pad, $pad, nope1, $pad",
    "select nope2, $pad, $pad, $pad, $pad, $pad",
    "select $pad, $pad, $pad, $pad, $pad, nope3",
    "select $pad, $pad, $pad, $pad, $pad, $pad, 1 from",
] as $q) {
    try { $db->exec($q); } catch (PDOException $e) { echo json_encode($e->getMessage()), "\n"; }
}
