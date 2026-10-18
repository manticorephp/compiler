<?php
// PDO::FETCH_KEY_PAIR on a result with a number of columns other than two throws a different exception message/shape than php.
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$st = $db->query('select 1 as a, 2 as b, 3 as c');
try { $st->fetchAll(PDO::FETCH_KEY_PAIR); } catch (PDOException $e) { echo $e->getMessage(), ' ', json_encode($e->errorInfo), "\n"; }
