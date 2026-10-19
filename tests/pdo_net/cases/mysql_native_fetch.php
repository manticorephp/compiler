<?php
class Row { public $id; public $v; public $g; public $n; public $extra = 'none'; }
$db = new PDO(getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db->exec('create temporary table t (id int primary key, g varchar(3), v varchar(10), n int)');
$db->exec("insert into t values (1,'a','one',10),(2,'a','two',null),(3,'b','three',30),(4,'c',null,40)");
$q = fn (string $sql = 'select id, g, v, n from t order by id') => $db->query($sql);
foreach (['ASSOC' => PDO::FETCH_ASSOC, 'NUM' => PDO::FETCH_NUM, 'BOTH' => PDO::FETCH_BOTH, 'OBJ' => PDO::FETCH_OBJ] as $name => $m) {
    $s = $q();
    echo $name, ' ', json_encode($s->fetch($m)), ' ', json_encode($s->fetchAll($m)), "\n";
}
$s = $q();
echo 'COLUMN ', json_encode($s->fetchAll(PDO::FETCH_COLUMN, 2)), "\n";
$s = $q('select id, v from t order by id');
echo 'KEY_PAIR ', json_encode($s->fetchAll(PDO::FETCH_KEY_PAIR)), "\n";
$s = $q();
echo 'UNIQUE ', json_encode($s->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC)), "\n";
$s = $q('select g, id, v from t order by id');
echo 'GROUP ', json_encode($s->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC)), "\n";
$s = $q('select g, id from t order by id');
echo 'GROUP_COLUMN ', json_encode($s->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_COLUMN)), "\n";
$s = $q('select id, v from t order by id');
$rows = $s->fetchAll(PDO::FETCH_CLASS, 'Row');
echo 'CLASS ', json_encode($rows), "\n";
$s = $q();
$o = $s->fetchObject('Row');
echo 'OBJECT ', json_encode($o), "\n";
$s = $q();
$s->setFetchMode(PDO::FETCH_ASSOC);
foreach ($s as $r) { echo json_encode($r), ';'; }
echo "\n";
$s = $q();
$cols = [];
while (($c = $s->fetchColumn(2)) !== false) { $cols[] = $c; }
echo 'fetchColumn ', json_encode($cols), "\n";
$s = $q('select id from t where id > 100');
var_dump($s->fetch(), $s->fetchAll(), $s->fetchColumn());
$st = $db->prepare('select id, v from t where id >= ? order by id');
$st->execute([3]);
echo json_encode($st->fetchAll(PDO::FETCH_NUM)), "\n";
$st->execute([1]);
echo json_encode($st->fetch(PDO::FETCH_ASSOC)), json_encode($st->fetch(PDO::FETCH_ASSOC)), "\n";
$st->closeCursor();
$st->execute([4]);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC)), "\n";
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_NUM);
$s = $q();
echo json_encode($s->fetch()), "\n";
$db->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$s = $q('select id, v from t where id = 1');
echo json_encode($s->fetch()), "\n";
$db->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_TO_STRING);
$s = $q('select id, v from t where id = 4');
echo json_encode($s->fetch()), "\n";
