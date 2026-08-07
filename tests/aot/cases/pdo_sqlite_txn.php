<?php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT UNIQUE)');
$db->exec("INSERT INTO t VALUES (1,'a'),(2,'b')");

var_dump($db->inTransaction());
$db->beginTransaction();
var_dump($db->inTransaction());
$db->exec("INSERT INTO t VALUES (10,'x')");
$db->rollBack();
var_dump($db->query('SELECT COUNT(*) FROM t')->fetchColumn());
var_dump($db->inTransaction());

$db->beginTransaction();
$db->exec("INSERT INTO t VALUES (11,'y')");
$db->commit();
var_dump($db->query('SELECT COUNT(*) FROM t')->fetchColumn());

try { $db->commit(); } catch (PDOException $e) { echo "commit: ", $e->getMessage(), "\n"; }
try { $db->rollBack(); } catch (PDOException $e) { echo "rollback: ", $e->getMessage(), "\n"; }
$db->beginTransaction();
try { $db->beginTransaction(); } catch (PDOException $e) { echo "begin: ", $e->getMessage(), "\n"; }
$db->rollBack();

echo "-- rowCount --\n";
var_dump($db->exec("UPDATE t SET name = name WHERE id < 3"));
$u = $db->prepare("UPDATE t SET name = name WHERE id < 3"); $u->execute();
var_dump($u->rowCount());
$s = $db->prepare("SELECT * FROM t"); $s->execute();
var_dump($s->rowCount(), $s->columnCount());
var_dump($s->closeCursor());

echo "-- quote --\n";
var_dump($db->quote("O'Reilly"), $db->quote('plain'), $db->quote('bin', PDO::PARAM_LOB));
try { $db->quote("a\x00b"); } catch (PDOException $e) { echo "NUL: ", $e->getMessage(), "\n"; }
