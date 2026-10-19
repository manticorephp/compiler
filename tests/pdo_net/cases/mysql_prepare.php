<?php
$db = new PDO(getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$one = function (string $sql, array $params) use ($db) {
    $st = $db->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_NUM);
    $st = null;
    echo json_encode($r), "\n";
};
$one('select ?, ?', [1, 'a']);
$one('select :a, :b', ['a' => 1, 'b' => "it's"]);
$one('select :a, :a, :b', ['a' => 'r', 'b' => 'q']);
$one("select '?', \"?\", ? -- ?\n, /* ? */ ?", ['x', 'y']);
$one('select ?, ?', [null, 'null']);
$one('select ?, ?, ?, ?', [true, false, 1.5, '007']);
$one('select ?', ["a'b\"c\\d\n\r\0\x1a"]);
$one('select ?', ['é']);
$one('select ?', ["\xff\xfe"]);
// multibyte text before a placeholder: byte offsets of the scan (deferred from the SQL scanner task)
$one("select 'é', ?", ['x']);
$one('select é, ? from (select 1 é) q', ['x']);
$one('select ?, ?, ?', [str_repeat('é', 1000), '', str_repeat('x', 100000)]);

$st = $db->prepare('select ?, ?, ?, ?, ?, ?, ?');
$st->bindValue(1, 5, PDO::PARAM_INT);
$st->bindValue(2, "x'y", PDO::PARAM_STR);
$st->bindValue(3, true, PDO::PARAM_BOOL);
$st->bindValue(4, null, PDO::PARAM_NULL);
$st->bindValue(5, 1.5);
$st->bindValue(6, false, PDO::PARAM_BOOL);
$f = fopen('php://memory', 'w+'); fwrite($f, "lob'x\0y"); rewind($f);
$st->bindValue(7, $f, PDO::PARAM_LOB);
$st->execute();
var_dump($st->fetch(PDO::FETCH_NUM));
$st = $db->prepare('select ?');
foreach ([['a', PDO::PARAM_INT], ['12abc', PDO::PARAM_INT], [7, PDO::PARAM_STR], [3.9, PDO::PARAM_INT], [true, PDO::PARAM_INT], ['yes', PDO::PARAM_BOOL], [0, PDO::PARAM_BOOL], [null, PDO::PARAM_STR], ["bin\0", PDO::PARAM_LOB]] as [$v, $t]) {
    $st->bindValue(1, $v, $t);
    $st->execute();
    echo json_encode($st->fetchColumn()), "\n";
}
// re-execute with the same statement
$st = $db->prepare('select ? + 1');
foreach ([1, 2, 3] as $n) { $st->execute([$n]); echo $st->fetchColumn(), ' '; }
echo "\n";

// count mismatches: php reports them at execute
foreach ([['select ?, ?', [1]], ['select ?, ?', [1, 2, 3]], ['select :a', ['b' => 1]], ['select :a, :b', ['a' => 1]]] as [$sql, $params]) {
    $st = $db->prepare($sql);
    // errorInfo of a core-raised error is [state, 0] in php, [state, null, msg] here (docs/pdo.md): only the state is compared
    try { $st->execute($params); echo "ok\n"; } catch (PDOException $e) { echo json_encode($e->getMessage()), ' ', $e->errorInfo[0], "\n"; }
}
$st = $db->prepare('select 1');
echo $st->execute() ? "no params ok\n" : "false\n";
// the server's error shows the substituted text
$st = $db->prepare('selec ?');
echo "prepared\n";
try { $st->execute([1]); } catch (PDOException $e) { echo json_encode($e->getMessage()), ' ', json_encode($e->errorInfo), "\n"; }
$st = $db->prepare('select * from nonexistent where a = :a');
try { $st->execute(['a' => 'x']); } catch (PDOException $e) { echo json_encode($e->getMessage()), "\n"; }

// quote(): mysqlnd's escaping for utf8mb4, no UTF-8 validation
foreach (["a'b\"c\\d\n\r\0\x1a", "\xff", 'é', '', "it's"] as $q) { echo json_encode($db->quote($q)), ' ', bin2hex($db->quote($q)), "\n"; }
echo json_encode($db->quote('x', PDO::PARAM_INT)), json_encode($db->quote('x', PDO::PARAM_LOB)), "\n";
$db->exec("set sql_mode = 'NO_BACKSLASH_ESCAPES'");
$st = $db->prepare('select ?');
$st->execute(["a'b\\c\"\n"]);
echo json_encode($st->fetchColumn()), "\n";
echo $db->quote("a'b\\c\""), "\n";
$db->exec("set sql_mode = ''");
$st = $db->prepare('select ?');
$st->execute(["a'b\\c"]);
echo json_encode($st->fetchColumn()), "\n";

// dml through a statement
$db->exec('create temporary table t (id int auto_increment primary key, v varchar(20))');
$st = $db->prepare('insert into t (v) values (?), (?)');
$st->execute(['a', 'b']);
var_dump($st->rowCount(), $st->columnCount(), $db->lastInsertId());
$st = $db->prepare('update t set v = :v where id = :id');
$st->execute(['v' => 'a', 'id' => 1]);
var_dump($st->rowCount());
$st->execute(['v' => 'q', 'id' => 1]);
var_dump($st->rowCount());
$st = $db->prepare('select v from t where v like ? order by id');
$st->execute(['%']);
var_dump($st->rowCount(), $st->fetchAll(PDO::FETCH_COLUMN));
$st->execute(['zz%']);
var_dump($st->rowCount(), $st->fetchAll(PDO::FETCH_COLUMN));
