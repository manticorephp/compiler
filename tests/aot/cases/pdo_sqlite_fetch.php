<?php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec("INSERT INTO t VALUES (1,'a'),(2,'b'),(3,'a')");
$q = function (string $sql) use ($db) { return $db->query($sql); };

echo "ASSOC: ", json_encode($q('SELECT id, name FROM t ORDER BY id')->fetch(PDO::FETCH_ASSOC)), "\n";
echo "NUM: ", json_encode($q('SELECT id, name FROM t ORDER BY id')->fetch(PDO::FETCH_NUM)), "\n";
echo "BOTH: ", json_encode($q('SELECT id, name FROM t ORDER BY id')->fetch(PDO::FETCH_BOTH)), "\n";
echo "NAMED: ", json_encode($q('SELECT id, name FROM t ORDER BY id')->fetch(PDO::FETCH_NAMED)), "\n";
echo "ALL ASSOC: ", json_encode($q('SELECT id, name FROM t ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)), "\n";
echo "COLUMN: ", json_encode($q('SELECT name FROM t ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)), "\n";
echo "KEY_PAIR: ", json_encode($q('SELECT id, name FROM t ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR)), "\n";
echo "GROUP: ", json_encode($q('SELECT name, id FROM t ORDER BY id')->fetchAll(PDO::FETCH_GROUP)), "\n";
echo "UNIQUE: ", json_encode($q('SELECT id, name FROM t ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE)), "\n";
echo "GROUP|COLUMN: ", json_encode($q('SELECT name, id FROM t ORDER BY id')->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_COLUMN)), "\n";
echo "FUNC: ", json_encode($q('SELECT id, name FROM t ORDER BY id')->fetchAll(PDO::FETCH_FUNC, fn($a, $b) => "$a:$b")), "\n";

echo "-- past the end --\n";
$e = $q('SELECT id FROM t WHERE id = 99');
var_dump($e->fetch(PDO::FETCH_ASSOC), $e->fetchColumn(), $e->fetchAll(PDO::FETCH_ASSOC));

echo "-- ATTR_CASE --\n";
$db->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
echo json_encode($q('SELECT id, name FROM t ORDER BY id')->fetch(PDO::FETCH_ASSOC)), "\n";
$db->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
echo json_encode($q('SELECT id AS Mixed FROM t ORDER BY id')->fetch(PDO::FETCH_ASSOC)), "\n";
$db->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);

echo "-- STRINGIFY --\n";
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
echo json_encode($q('SELECT id FROM t ORDER BY id')->fetch(PDO::FETCH_ASSOC)), "\n";
