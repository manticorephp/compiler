<?php
// A DSN the program does not spell as a literal scheme at the construction site gets every driver.
$dsn = 'sqlite::memory:';
$db = new PDO($dsn);
$db->exec('create table t (a int)');
$db->exec('insert into t values (1)');
echo $db->query('select a from t')->fetchColumn(), "\n";
var_dump(class_exists('__McPdoPgWire'), class_exists('__McPdoMyWire'));
