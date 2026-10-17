<?php
// native prepared statements (COM_STMT_PREPARE / EXECUTE): binding, typing of parameters and results, long values
$db = new PDO((string) getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
var_dump($db->getAttribute(PDO::ATTR_EMULATE_PREPARES));
function one(PDO $db, string $sql, array $params): void
{
    $st = $db->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_NUM);
    echo json_encode($r), ' ', json_encode(array_map('gettype', $r)), "\n";
    $st = null;
}
one($db, 'select ?, ?', [1, 'a']);
one($db, 'select :a, :b', ['a' => 1, 'b' => "it's"]);
one($db, 'select :b, :a', ['a' => 1, 'b' => 2]);
one($db, "select '?', \"?\", ? -- ?\n, /* ? */ ?", ['x', 'y']);
one($db, 'select ?, ?', [null, 'null']);
one($db, 'select ?, ?, ?, ?', [true, false, 1.5, '007']);
one($db, 'select ?', ["a'b\"c\\d\n\r\0\x1a"]);
one($db, 'select ?, ?, ?', ['é', "\xf0\x9f\x98\x80", "\xe4\xb8\xad\xe6\x96\x87"]);
one($db, 'select ?', ["\xff\xfe"]);
one($db, "select 'é', ?", ['x']);
one($db, 'select é, ? from (select 1 é) q', ['x']);
one($db, 'select ?, ?, ?', [str_repeat('é', 1000), '', str_repeat('x', 100000)]);
one($db, 'select ?, ?, ?, ?', [PHP_INT_MAX, PHP_INT_MIN, 0, -1]);
one($db, 'select ?, ?, ?', [0.1, 1e300, -2.5]);
one($db, 'select ?, ?', [str_repeat('a', 250), str_repeat('b', 251)]);
one($db, 'select ?, ?', [str_repeat('c', 65535), str_repeat('d', 65536)]);
one($db, 'select length(?), length(?)', [str_repeat('e', 16777215), str_repeat('f', 16777216)]);

$types = [PDO::PARAM_INT => 'INT', PDO::PARAM_STR => 'STR', PDO::PARAM_BOOL => 'BOOL', PDO::PARAM_NULL => 'NULL', PDO::PARAM_LOB => 'LOB'];
$vals = [1, -7, PHP_INT_MAX, PHP_INT_MIN, 1.5, -0.0, 1e100, true, false, '7', 'abc', '', null, '5.5', "x\0y"];
foreach ($vals as $v) {
    foreach ($types as $t => $tn) {
        $st = $db->prepare('select ?');
        $st->bindValue(1, $v, $t);
        $st->execute();
        $r = $st->fetch(PDO::FETCH_NUM);
        $m = $st->getColumnMeta(0);
        echo json_encode($v), ' ', $tn, ' => ', is_int($r[0]) ? 'int(' . $r[0] . ')' : var_export($r[0], true), ' ', $m['native_type'], "\n";
    }
}
// a stream as LOB
$f = fopen('php://memory', 'w+'); fwrite($f, "lob'x\0y"); rewind($f);
$st = $db->prepare('select ?, length(?)');
$st->bindValue(1, $f, PDO::PARAM_LOB);
$st->bindValue(2, 'abc', PDO::PARAM_LOB);
$st->execute();
var_dump($st->fetch(PDO::FETCH_NUM));

// bound values stay across executions, rebinding replaces them, execute(array) replaces all
$st = $db->prepare('select ?, ?');
$st->bindValue(1, 'a');
$st->bindValue(2, 'b');
$st->execute();
echo json_encode($st->fetch(PDO::FETCH_NUM)), "\n";
$st->execute();
echo json_encode($st->fetch(PDO::FETCH_NUM)), "\n";
$st->bindValue(2, 'B', PDO::PARAM_STR);
$st->execute();
echo json_encode($st->fetch(PDO::FETCH_NUM)), "\n";
$st->execute(['x', 'y']);
echo json_encode($st->fetch(PDO::FETCH_NUM)), "\n";
$st->bindValue(1, 3, PDO::PARAM_INT);
$st->bindValue(2, 4, PDO::PARAM_INT);
$st->execute();
echo json_encode($st->fetch(PDO::FETCH_NUM)), "\n";
$st->bindValue(1, 'now', PDO::PARAM_STR);
$st->bindValue(2, null, PDO::PARAM_NULL);
$st->execute();
echo json_encode($st->fetch(PDO::FETCH_NUM)), "\n";
$st->execute([null, 1.25]);
echo json_encode($st->fetch(PDO::FETCH_NUM)), "\n";
$st = $db->prepare('select ? + ?');
foreach ([[1, 2], [3, 4], [PHP_INT_MAX, 0]] as $p) { $st->execute($p); echo $st->fetchColumn(), ' '; }
echo "\n";
$x = 5;
$st = $db->prepare('select ?');
$st->bindParam(1, $x, PDO::PARAM_INT);
$st->execute();
echo $st->fetchColumn(), "\n";
$st = $db->prepare('select 1');
echo $st->execute() ? "no params ok\n" : "false\n";

// round trip of binary and big values through a real table
$db->exec('create temporary table b (id int primary key, a blob, m mediumblob, l longblob, t text, c char(3), j json, d decimal(20,5), f double, i bigint unsigned, ts datetime(6), tm time(3), y year, e enum(\'x\',\'y\'), s set(\'p\',\'q\'), bt bit(10))');
$ins = $db->prepare('insert into b values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$blob = random_bytes(1500000);
$bin = '';
for ($i = 0; $i < 256; $i++) { $bin .= chr($i); }
$ins->bindValue(1, 1, PDO::PARAM_INT);
$ins->bindValue(2, $bin, PDO::PARAM_LOB);
$ins->bindValue(3, $blob, PDO::PARAM_LOB);
$ins->bindValue(4, str_repeat("\0", 70000), PDO::PARAM_LOB);
$ins->bindValue(5, "päivää \xf0\x9f\x98\x80", PDO::PARAM_STR);
$ins->bindValue(6, 'abc', PDO::PARAM_STR);
$ins->bindValue(7, '{"k": [1, 2, {"z": null}]}', PDO::PARAM_STR);
$ins->bindValue(8, '123456789012345.12345', PDO::PARAM_STR);
$ins->bindValue(9, 0.1, PDO::PARAM_STR);
$ins->bindValue(10, '18446744073709551615', PDO::PARAM_STR);
$ins->bindValue(11, '2020-02-29 23:59:59.123456', PDO::PARAM_STR);
$ins->bindValue(12, '-12:34:56.789', PDO::PARAM_STR);
$ins->bindValue(13, 2024, PDO::PARAM_INT);
$ins->bindValue(14, 'y', PDO::PARAM_STR);
$ins->bindValue(15, 'p,q', PDO::PARAM_STR);
$ins->bindValue(16, 513, PDO::PARAM_INT);
$ins->execute();
echo 'inserted ', $ins->rowCount(), ' id ', $db->lastInsertId(), "\n";
$ins->execute([2, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null]);
echo 'inserted ', $ins->rowCount(), "\n";
$sel = $db->prepare('select * from b where id = ?');
$sel->execute([1]);
$r = $sel->fetch(PDO::FETCH_ASSOC);
echo bin2hex($r['a']) === bin2hex($bin) ? "a ok\n" : "a BAD\n";
echo md5($r['m']) === md5($blob) ? "m ok\n" : "m BAD\n";
echo strlen($r['l']), ' ', $r['l'] === str_repeat("\0", 70000) ? "l ok\n" : "l BAD\n";
unset($r['a'], $r['m'], $r['l']);
var_dump($r);
$sel->execute([2]);
echo json_encode($sel->fetch(PDO::FETCH_NUM)), "\n";
$sel->execute([3]);
var_dump($sel->fetch());
// bound through an expression
$st = $db->prepare('select id, length(m) from b where length(m) > ? and id in (?, ?) order by id');
$st->execute([1000000, 1, 2]);
echo json_encode($st->fetchAll(PDO::FETCH_NUM)), "\n";
// 40000 parameters is the protocol's limit of 65535
$sql = 'select ' . implode(', ', array_fill(0, 3000, '?'));
$st = $db->prepare($sql);
$st->execute(range(1, 3000));
$r = $st->fetch(PDO::FETCH_NUM);
echo count($r), ' ', $r[0], ' ', $r[2999], ' ', array_sum($r), "\n";
$st = $db->prepare('select ' . implode(', ', array_fill(0, 600, '?')) . ' from (select 1) q where 1 = ?');
$p = array_fill(0, 600, 'v'); $p[] = 1;
$st->execute($p);
echo count($st->fetch(PDO::FETCH_NUM)), "\n";
