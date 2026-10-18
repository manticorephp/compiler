<?php
// A PDO program that names only sqlite links neither network driver.
$db = new PDO('sqlite::memory:');
$db->exec('create table t (a int)');
$db->exec('insert into t values (1)');
echo $db->query('select a from t')->fetchColumn(), "\n";
var_dump(class_exists('__McPdoPgWire'), class_exists('__McPdoMyWire'), class_exists('__McPdoSqliteDrv'));
try { new PDO('p' . 'gsql:host=x'); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
