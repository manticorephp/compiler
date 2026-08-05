<?php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec("INSERT INTO t VALUES (1,'a'),(2,'b'),(3,'c')");

$s = $db->prepare('SELECT name FROM t WHERE id = :id');
$s->execute([':id' => 2]); var_dump($s->fetchColumn());
$s->execute(['id' => 3]);  var_dump($s->fetchColumn());
$s->bindValue(':id', 1);   $s->execute(); var_dump($s->fetchColumn());

$p = $db->prepare('SELECT name FROM t WHERE id = ?');
$p->execute([2]); var_dump($p->fetchColumn());

// a placeholder inside a quoted literal or a comment is not a placeholder
$q = $db->prepare("SELECT ':id' AS lit, id FROM t WHERE id = :id -- :nope");
var_dump($q->execute([':id' => 1]));
var_dump($q->fetch(PDO::FETCH_ASSOC));

var_dump($db->prepare('SELECT 1')->execute([]));
echo "queryString=", $db->prepare('SELECT 1 AS one')->queryString, "\n";
