<?php
// Async\Pool::pdo() opens the PDO inside the prelude, so a literal sqlite DSN elsewhere must not narrow the drivers.
use Async\Pool;
$db = new PDO('sqlite::memory:');
echo $db->query('select 1')->fetchColumn(), "\n";
if (false) { Pool::pdo('pgsql:host=127.0.0.1;port=1;dbname=d', 'u', 'pw'); }
var_dump(class_exists('__McPdoPgWire'), class_exists('__McPdoMyWire'));
