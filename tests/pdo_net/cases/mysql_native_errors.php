<?php
// native prepares: where each error is raised (prepare vs execute), its shape, and what the statement can do afterwards
set_error_handler(function ($n, $s) { if (!(error_reporting() & $n)) { return false; } echo "WARN: $s\n"; return true; });
function pdo(int $mode): PDO { return new PDO((string) getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => $mode, PDO::ATTR_EMULATE_PREPARES => false]); }
function info($x): string { return json_encode([$x->errorCode(), $x->errorInfo()]); }
function step(string $label, callable $f): void
{
    try { $r = $f(); echo $label, ' => ', json_encode($r), "\n"; }
    catch (PDOException $e) { echo $label, ' => EXC ', json_encode($e->getMessage()), ' ', json_encode([$e->errorInfo[0], ($e->errorInfo[1] ?? 0) ?: null]), "\n"; }
}
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING, PDO::ERRMODE_EXCEPTION] as $mode) {
    echo "== errmode $mode\n";
    $db = pdo($mode);
    $db->exec('create temporary table t (a int not null primary key, b varchar(5), c int, unique key (c))');
    $db->exec("insert into t values (1, 'x', null)");

    // raised by PREPARE: syntax, unknown table, unknown column
    foreach (['selec ?', 'select * from nope where a = ?', 'select nocol from t where a = ?', 'insert into t values (?, ?', 'select ? from', ' ', 'select 1; select 2', 'select ?; select ?'] as $sql) {
        step('prepare ' . json_encode($sql), function () use ($db, $sql) { $s = $db->prepare($sql); return $s === false ? 'false ' . info($db) : 'stmt'; });
    }
    // a failed execute leaves the statement usable
    $ins = $db->prepare('insert into t values (?, ?, ?)');
    step('insert 2', function () use ($ins) { $r = $ins->execute([2, 'b', 2]); return [$r, $ins->rowCount()]; });
    step('insert dup pk', function () use ($ins) { $r = $ins->execute([2, 'b', 3]); return [$r, info($ins), $ins->rowCount()]; });
    step('insert dup unique', function () use ($ins) { $r = $ins->execute([3, 'b', 2]); return [$r, info($ins)]; });
    step('insert too long', function () use ($ins) { $r = $ins->execute([4, 'toolongvalue', 4]); return [$r, info($ins)]; });
    step('insert bad int', function () use ($ins) { $r = $ins->execute(['abc', 'b', 5]); return [$r, info($ins)]; });
    step('insert 4 after failures', function () use ($ins) { $r = $ins->execute([4, 'b', 4]); return [$r, info($ins), $ins->rowCount()]; });
    // error info of a statement clears on the next success
    step('errorInfo after success', function () use ($ins) { return info($ins); });

    // execute on a table dropped after prepare
    $db->exec('create temporary table gone (a int)');
    $g = $db->prepare('select * from gone where a = ?');
    $db->exec('drop temporary table gone');
    step('execute after drop', function () use ($g) { $r = $g->execute([1]); return [$r, info($g)]; });
    $db->exec('create temporary table gone (a int, b int)');
    step('execute after recreate', function () use ($g) { $r = $g->execute([1]); return [$r, $g->columnCount()]; });

    // runtime errors of the statement itself
    step('division by zero in select', function () use ($db) { $s = $db->prepare('select 1/?'); $r = $s->execute([0]); return [$r, $s->fetch(PDO::FETCH_NUM)]; });
    $db->exec("set sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO'");
    step('strict division by zero in insert', function () use ($db) { $s = $db->prepare('insert into t values (10, ?, 1/?)'); $r = $s->execute(['x', 0]); return [$r, info($s)]; });
    step('select with out-of-range cast', function () use ($db) { $s = $db->prepare('select cast(? as signed)'); $r = $s->execute(['99999999999999999999']); return [$r, $s->fetch(PDO::FETCH_NUM)]; });
    $db->exec("set sql_mode = default");

    // fetching without or before execute, or past the end
    $s = $db->prepare('select a from t where a = ?');
    step('fetch before execute', function () use ($s) { return [$s->fetch(), $s->columnCount(), $s->rowCount(), $s->getColumnMeta(0)]; });
    step('execute no rows', function () use ($s) { $r = $s->execute([999]); return [$r, $s->fetch(), $s->columnCount(), $s->rowCount(), $s->getColumnMeta(0)['name']]; });
    step('execute 1 row', function () use ($s) { $r = $s->execute([1]); return [$r, $s->rowCount(), $s->columnCount(), $s->fetchAll(PDO::FETCH_COLUMN), $s->fetch()]; });
    step('closeCursor then fetch', function () use ($s) { $s->execute([1]); $c = $s->closeCursor(); return [$c, $s->fetch()]; });
    step('execute twice without fetch', function () use ($s) { $s->execute([1]); $r = $s->execute([2]); return [$r, $s->fetchAll(PDO::FETCH_COLUMN)]; });
    step('nextRowset on a plain select', function () use ($s) { $s->execute([1]); return $s->nextRowset(); });

    // the connection stays healthy after every one of those
    step('still alive', function () use ($db) { $q = $db->query('select count(*) from t'); return $q->fetchColumn(); });
}
echo "== placeholders (exception mode)\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
// placeholder counts: php checks them itself (HY093) and never sends the execute
$st = $db->prepare('select ?, ?');
step('execute 1 of 2', function () use ($st) { $r = $st->execute([1]); return [$r, info($st)]; });
step('execute 3 of 2', function () use ($st) { $r = $st->execute([1, 2, 3]); return [$r, info($st)]; });
step('execute 0 of 2', function () use ($st) { $r = $st->execute(); return [$r, info($st)]; });
step('execute ok', function () use ($st) { $r = $st->execute([1, 2]); return [$r, $st->fetch(PDO::FETCH_NUM)]; });
$st = $db->prepare('select :a, :b');
step('named wrong key', function () use ($st) { $r = $st->execute(['a' => 1, 'c' => 2]); return [$r, info($st)]; });
step('named missing', function () use ($st) { $r = $st->execute(['a' => 1]); return [$r, info($st)]; });
step('named ok', function () use ($st) { $r = $st->execute(['a' => 1, 'b' => 'z']); return [$r, $st->fetch(PDO::FETCH_NUM)]; });
step('named with colon', function () use ($st) { $r = $st->execute([':a' => 1, ':b' => 'z']); return [$r, $st->fetch(PDO::FETCH_NUM)]; });
$st = $db->prepare('select :a, :a');
step('repeated name', function () use ($st) { $r = $st->execute(['a' => 1]); return [$r, info($st)]; });
step('repeated name by position', function () use ($st) { $r = $st->execute([1, 2]); return [$r, info($st)]; });
step('repeated name bindValue', function () use ($st) { $st->bindValue(':a', 5); $r = $st->execute(); return [$r, info($st)]; });
step('mixed', function () use ($db) { $s = $db->prepare('select ?, :a'); return $s === false ? 'false ' . info($db) : 'stmt'; });
step('bindValue past the end', function () use ($db) { $s = $db->prepare('select ?'); $b = $s->bindValue(2, 1); $r = $s->execute(); return [$b, $r, info($s)]; });
step('bindValue unknown name', function () use ($db) { $s = $db->prepare('select :x'); $b = $s->bindValue(':y', 1); return [$b, info($s)]; });
step('statement without placeholders', function () use ($db) { $s = $db->prepare('select 1'); $r = $s->execute([5]); return [$r, info($s)]; });
step('question mark in literals', function () use ($db) { $s = $db->prepare("select '?', \"?\", ? -- ?\n, /* ? */ ?"); $r = $s->execute(['x', 'y']); return [$r, $s->fetch(PDO::FETCH_NUM)]; });


step('named by position', function () use ($db) { $s = $db->prepare('select :a, :b'); $r = $s->execute([1, 2]); return [$r, $s->fetch(PDO::FETCH_NUM)]; });
step('named by 1-based bindValue', function () use ($db) { $s = $db->prepare('select :a, :b'); $s->bindValue(1, 'x'); $s->bindValue(2, 'y'); $r = $s->execute(); return [$r, $s->fetch(PDO::FETCH_NUM)]; });
step('repeated name by index', function () use ($db) { $s = $db->prepare('select :a, :b, :a'); $s->bindValue(1, 'x'); $s->bindValue(2, 'y'); $s->bindValue(3, 'z'); $r = $s->execute(); return [$r, $s->fetch(PDO::FETCH_NUM)]; });
step('a bound extra stays bound', function () use ($db) { $s = $db->prepare('select ?, ?'); try { $s->execute([1, 2, 3]); } catch (PDOException $e) { } $r = $s->execute(); return [$r, $s->fetch(PDO::FETCH_NUM)]; });
step('a missing name stays unbound', function () use ($db) { $s = $db->prepare('select :a, :b'); try { $s->execute(['a' => 1, 'c' => 2]); } catch (PDOException $e) { } $r = $s->execute(); return [$r, $s->fetch(PDO::FETCH_NUM)]; });
echo "== exception form of a prepare error\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
try { $s = $db->prepare('selec 1'); echo "no error\n"; } catch (PDOException $e) { echo $e->getMessage(), "\n", json_encode($e->errorInfo), "\n"; }
echo "== switching the attribute at run time\n";
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$s = $db->prepare('selec 1');
echo "emulated prepare of a bad statement: ok\n";
try { $s->execute(); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
try { $s = $db->prepare('selec 1'); echo "no error\n"; } catch (PDOException $e) { echo "native: prepare raises\n"; }
var_dump($db->getAttribute(PDO::ATTR_EMULATE_PREPARES));
echo "== a closed statement and a dead connection\n";
$db = pdo(PDO::ERRMODE_EXCEPTION);
$k = pdo(PDO::ERRMODE_EXCEPTION);
$qi = $db->query('select connection_id()'); $id = $qi->fetchColumn(); $qi = null;
$s = $db->prepare('select 1');
$s->execute();
$k->exec('kill ' . $id);
step('execute on a killed connection', function () use ($s) { $r = $s->execute(); return $r; });
step('prepare on a killed connection', function () use ($db) { $x = $db->prepare('select 2'); return $x === false ? 'false' : 'stmt'; });
$s = null;
echo "destructed on a dead connection\n";
