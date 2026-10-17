<?php
// var_dump of an object produced by PDO::FETCH_CLASS with constructor arguments prints a stdClass with a garbage property count.
// issue: #303
class Row { public $a; public $b; public function __construct(public string $tag = 'none') {} }
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$st = $db->query("select 1 as a, 'x' as b");
var_dump($st->fetchAll(PDO::FETCH_CLASS, 'Row', ['T']));
