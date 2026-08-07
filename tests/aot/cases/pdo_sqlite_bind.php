<?php

/*
 * bindParam() — a `mixed &$var` parameter forwarded to bindValue(). The raw
 * caller slot read as a cell bound a denormal, so every bindParam() lookup
 * missed. bindValue() with the same value was always fine.
 */

$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
foreach (['a', 'b', 'c'] as $n) { $db->exec("INSERT INTO t (name) VALUES ('$n')"); }
$q = 'SELECT name FROM t WHERE id = ?';

$s = $db->prepare($q); $v = 3;   $s->bindParam(1, $v);                    $s->execute(); var_dump($s->fetchColumn());
$s = $db->prepare($q); $w = '2'; $s->bindParam(1, $w, PDO::PARAM_STR);    $s->execute(); var_dump($s->fetchColumn());
$s = $db->prepare($q); $x = 1;   $s->bindParam(1, $x, PDO::PARAM_INT);    $s->execute(); var_dump($s->fetchColumn());

$s = $db->prepare('SELECT name FROM t WHERE name = :n');
$n = 'b'; $s->bindParam(':n', $n); $s->execute(); var_dump($s->fetchColumn());

// bindValue then bindParam on the same statement
$s = $db->prepare($q);
$y = 2; $s->bindValue(1, $y, PDO::PARAM_INT); $s->execute(); var_dump($s->fetchColumn());
$z = 3; $s->bindParam(1, $z);                 $s->execute(); var_dump($s->fetchColumn());

$s = $db->prepare('SELECT ? + 0');           $f = 2.5;   $s->bindParam(1, $f); $s->execute(); var_dump($s->fetchColumn());
$s = $db->prepare('SELECT ifnull(?, 42)');   $nu = null; $s->bindParam(1, $nu, PDO::PARAM_NULL); $s->execute(); var_dump($s->fetchColumn());

// iteration helpers over a statement
$s = $db->query('SELECT id FROM t ORDER BY id');
print_r(iterator_to_array($s, false));
echo iterator_count($db->query('SELECT id FROM t')), "\n";
