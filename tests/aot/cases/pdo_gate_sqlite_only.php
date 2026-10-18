<?php
// A PDO program whose every `new PDO(..)` spells a literal sqlite DSN links neither network driver.
$db = new PDO('sqlite::memory:');
$db->exec('create table t (a int)');
$db->exec('insert into t values (1)');
echo $db->query('select a from t')->fetchColumn(), "\n";
var_dump(class_exists('__McPdoPgWire'), class_exists('__McPdoMyWire'));
$cls = 'PDO';
try { new $cls('pgsql:host=x'); } catch (PDOException $e) { echo substr($e->getMessage(), 0, 52), "\n"; }
