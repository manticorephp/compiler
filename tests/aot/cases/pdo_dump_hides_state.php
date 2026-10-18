<?php
// print_r / var_dump of a connected PDO show what php shows: an empty object (and the query string of a statement).
$db = new PDO('sqlite::memory:');
$st = $db->query('select 1');
print_r($db);
print_r($st);
var_dump($db);
