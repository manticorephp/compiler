<?php
class Row { public $a; public $b; public $c; public function __construct(public string $tag = 'none') {} }
class Plain { public $a; public $b; public $c; }
$db = new PDO(getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function d(mixed $v): void
{
    $o = function (mixed $x) { if (!is_object($x)) { return $x; } $a = get_object_vars($x); ksort($a); return $a; };
    echo json_encode(is_array($v) ? array_map($o, $v) : $o($v), JSON_PARTIAL_OUTPUT_ON_ERROR), ' ', is_object($v) ? get_class($v) : (is_array($v) && isset($v[0]) && is_object($v[0]) ? get_class($v[0]) : ''), "\n";
}
function t(callable $f): void
{
    try { var_dump($f()); } catch (PDOException $e) { echo get_class($e), ' ', json_encode($e->getMessage()), ' ', json_encode($e->errorInfo), "\n"; }
}
$db->exec('create temp table t(a int, b text, c float8)');
$db->exec("insert into t values (1,'one',1.5),(2,'two',null),(2,'dos',2.5),(3,null,3.5)");
$q = 'select a, b, c from t where a >= ? order by a, b nulls last';
foreach ([PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_BOTH, PDO::FETCH_OBJ, PDO::FETCH_NAMED, PDO::FETCH_COLUMN, PDO::FETCH_CLASS,
          PDO::FETCH_COLUMN | PDO::FETCH_GROUP, PDO::FETCH_ASSOC | PDO::FETCH_GROUP, PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE] as $m) {
    $st = $db->prepare($q); $st->execute([1]);
    echo "fetchAll $m\n"; d($st->fetchAll($m));
}
$st = $db->prepare('select a, b from t where a >= ? order by a, b'); $st->execute([1]);
echo "KEY_PAIR\n"; d($st->fetchAll(PDO::FETCH_KEY_PAIR));
$st = $db->prepare($q); $st->execute([1]);
echo "CLASS ctor\n"; d($st->fetchAll(PDO::FETCH_CLASS, 'Row', ['T']));
$st = $db->prepare($q); $st->execute([2]); $st->setFetchMode(PDO::FETCH_CLASS, 'Plain');
echo "setFetchMode CLASS\n"; d($st->fetch()); d($st->fetch());
$st = $db->prepare($q); $st->execute([1]);
echo "fetch loop\n";
while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) { echo json_encode($r), "\n"; }
var_dump($st->fetch(), $st->fetchColumn(), $st->fetchAll());
$st = $db->prepare($q); $st->execute([1]);
var_dump($st->fetchColumn(1), $st->fetchColumn(2)); d($st->fetchObject('Plain')); d($st->fetchObject());
foreach ($db->query('select g from generate_series(1,3) g') as $k => $r) { echo $k, ' ', json_encode($r), "\n"; }
$st = $db->prepare('select ?::int as x, ?::text as x'); $st->execute([1, 'dup']);
var_dump($st->fetch(PDO::FETCH_ASSOC)); $st->execute([1, 'dup']); var_dump($st->fetch(PDO::FETCH_NAMED));
$st = $db->prepare('select a from t where a > ?'); $st->execute([99]);
var_dump($st->fetch(), $st->rowCount(), $st->columnCount());
$st->execute([99]); var_dump($st->fetchAll());
// execute again while rows are pending
$st = $db->prepare($q); $st->execute([1]); $st->fetch(); $st->execute([3]); var_dump($st->fetchAll(PDO::FETCH_NUM));
// columnCount before / after execute
$st = $db->prepare($q); var_dump($st->columnCount()); $st->execute([1]); var_dump($st->columnCount());
// a big result
$st = $db->prepare('select g, g::text, g::float8 / 4 from generate_series(1, ?) g'); $st->execute([20000]);
$sum = 0; $n = 0;
while ($r = $st->fetch(PDO::FETCH_NUM)) { $sum += $r[0] + strlen($r[1]) + $r[2]; $n++; }
var_dump($n, $sum);
