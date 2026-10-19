<?php
$db = new PDO(getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
var_dump($db->exec('create temporary table t (id int auto_increment primary key, a int not null, b varchar(10))'));
var_dump($db->lastInsertId());
var_dump($db->exec("insert into t (a, b) values (1, 'x'), (2, 'y'), (3, null)"));
var_dump($db->lastInsertId());
var_dump($db->exec("update t set b = 'z' where a > 1"));
var_dump($db->exec("update t set b = 'z' where a > 1"));
var_dump($db->exec('delete from t where a = 99'));
var_dump($db->exec('set @x = 1'));
// a multi-statement exec answers the FIRST statement's count; every statement runs
var_dump($db->exec("insert into t (a) values (10),(11); insert into t (a) values (12)"));
$s = $db->query('select count(*) from t');
var_dump($s->fetchColumn());
$s = null;
$s = $db->query('select a, b from t order by a');
var_dump($s->rowCount(), $s->columnCount());
var_dump($s->fetchAll(PDO::FETCH_ASSOC));
$s = $db->query('select a from t where a = 99');
var_dump($s->fetch(), $s->rowCount(), $s->columnCount());
$s = null;
$s = $db->query('select 1 a; select 2 b');
var_dump($s->fetchAll(PDO::FETCH_ASSOC), $s->nextRowset(), $s->fetchAll(PDO::FETCH_ASSOC), $s->nextRowset());
$s = null;
var_dump($db->exec('update t set a = a + 100 where a = 1; update t set a = a + 100 where a > 1'));
$s = $db->query('select count(*) from t where a > 100');
var_dump($s->fetchColumn());
$s = null;
foreach (['selec 1', 'select nope from t', 'select * from nonexistent', 'insert into t (id, a) values (1, 5)', 'insert into t (b) values (5)',
          "insert into t (a, b) values (1, 'way too long for ten')", "insert into t (a) values ('abc')", 'insert into t (a) values (1); selec 2'] as $q) {
    try { $r = $db->exec($q); echo 'ok ', json_encode($r), "\n"; }
    catch (PDOException $e) { echo json_encode($e->getMessage()), ' ', json_encode($e->errorInfo), "\n"; }
}
var_dump($db->inTransaction());
$db->beginTransaction();
var_dump($db->inTransaction());
$db->exec('insert into t (a) values (500)');
$db->rollBack();
var_dump($db->inTransaction());
$s = $db->query('select count(*) from t where a = 500');
var_dump($s->fetchColumn());
$s = null;
$db->beginTransaction();
$db->exec('insert into t (a) values (501)');
$db->commit();
$s = $db->query('select count(*) from t where a = 501');
var_dump($s->fetchColumn());
$s = null;
$db->exec('start transaction');
var_dump($db->inTransaction());
$db->exec('commit');
var_dump($db->inTransaction());
$db->exec('insert into t (id, a) values (9000, 1)');
var_dump($db->lastInsertId(), $db->lastInsertId('whatever'));
// mysql_insert_id() follows the latest command: multi-statement last wins, a SELECT or UPDATE resets it
$db->exec('create temporary table u (id int auto_increment primary key, v int)');
$db->exec('insert into u (v) values (1),(2),(3); insert into u (v) values (4)');
var_dump($db->lastInsertId());
$s = $db->query('select 1');
$s->fetchAll();
$s = null;
var_dump($db->lastInsertId());
$db->exec('insert into u (v) values (5)');
$db->exec('update u set v = v + 1');
var_dump($db->lastInsertId());
