<?php
// A DSN no literal in the program spells out gets every driver.
$db = new PDO('sq' . 'lite::memory:');
$db->exec('create table t (a int)');
$db->exec('insert into t values (1)');
echo $db->query('select a from t')->fetchColumn(), "\n";
var_dump(class_exists('__McPdoPgWire'), class_exists('__McPdoMyWire'));
