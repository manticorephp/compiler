<?php

/*
 * The DEFAULT fetch mode — `fetch()` with no argument, and therefore
 * `foreach ($stmt as $row)`. PDO::query() omits its `?int $fetchMode = null`
 * ahead of `mixed ...$fetchModeArgs`, which is what made every row come back
 * as an object.
 */

$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE t (id INTEGER, name TEXT)');
$db->exec("INSERT INTO t VALUES (1,'a')");
$db->exec("INSERT INTO t VALUES (2,'b')");
$sql = 'SELECT id, name FROM t';

echo "-- prepare + execute + fetch()\n";
$s = $db->prepare($sql);
$s->execute();
var_dump($s->fetch());

echo "-- query() + fetch()\n";
$s = $db->query($sql);
var_dump($s->fetch());

echo "-- query() with an explicit mode\n";
$s = $db->query($sql, PDO::FETCH_ASSOC);
var_dump($s->fetch());

echo "-- fetch() with the mode in a variable\n";
$s = $db->query($sql);
$m = PDO::FETCH_NUM;
var_dump($s->fetch($m));

echo "-- foreach\n";
$s = $db->query($sql);
foreach ($s as $row) { var_dump($row); }

echo "-- setFetchMode + fetch()\n";
$s = $db->query($sql);
$s->setFetchMode(PDO::FETCH_NUM);
var_dump($s->fetch());

echo "-- ATTR_DEFAULT_FETCH_MODE\n";
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$s = $db->query($sql);
var_dump($s->fetch());
var_dump($s->fetchAll());

echo "-- fetchAll() with the default restored\n";
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_BOTH);
$s = $db->query('SELECT id FROM t');
var_dump($s->fetchAll());
