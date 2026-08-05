<?php
$db = new PDO('sqlite::memory:');
echo $db->getAttribute(PDO::ATTR_DRIVER_NAME), "\n";
var_dump($db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, w REAL)'));
var_dump($db->exec("INSERT INTO t VALUES (1,'a',1.5),(2,'b',2.5)"));
var_dump($db->lastInsertId());
// getAvailableDrivers() is NOT compared: php lists every driver its build has.
var_dump(in_array("sqlite", PDO::getAvailableDrivers(), true));
$st = $db->query('SELECT id, name, w FROM t ORDER BY id');
while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
    echo $r['id'], "|", $r['name'], "|", $r['w'], "\n";
}
var_dump($db->exec("CREATE TABLE m1 (a); CREATE TABLE m2 (a); INSERT INTO m1 VALUES (1)"));
var_dump($db->query('SELECT COUNT(*) FROM m2')->fetchColumn());
