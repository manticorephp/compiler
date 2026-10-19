<?php
// `use PDO as Db` hides construction sites: a literal sqlite site elsewhere must not narrow the drivers.
use PDO as Db;
$a = new PDO('sqlite::memory:');
$dsn = 'sqlite::memory:';
$db = new Db($dsn);
echo $db->query('select 1')->fetchColumn(), "\n";
var_dump(class_exists('__McPdoPgWire'), class_exists('__McPdoMyWire'));
