<?php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE t (a TEXT)');
$st = $db->prepare('INSERT INTO t VALUES (?)');
$st->execute(['SELECT * FROM mysqli_users -- mysqli']);
var_dump($db->query('SELECT a FROM t')->fetchColumn());
var_dump(class_exists('Foo'));
