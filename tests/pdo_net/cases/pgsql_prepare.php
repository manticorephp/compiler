<?php
$db = new PDO(getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function t(callable $f): void
{
    try { var_dump($f()); } catch (PDOException $e) { echo get_class($e), ' ', json_encode($e->getMessage()), ' ', json_encode($e->errorInfo), "\n"; }
}
$db->exec('create temp table t(a serial primary key, b text, c int)');

// positional and named, rebinding, repeated name, ?::int
$st = $db->prepare('insert into t(b, c) values (?, ?)');
var_dump($st->execute(['x', 1]), $st->rowCount());
var_dump($st->execute(['y', 2]), $st->rowCount());
$st = $db->prepare('insert into t(b, c) values (:b, :c)');
var_dump($st->execute(['b' => 'z', 'c' => 3]), $st->execute([':b' => 'w', ':c' => 4]));
$st->bindValue(':b', 'v'); $st->bindValue('c', 5, PDO::PARAM_INT);
var_dump($st->execute());
$st->bindValue(':c', 6, PDO::PARAM_INT);
var_dump($st->execute());
$st = $db->prepare('select ?::int + ?::int as s');
foreach ([[1, 2], [10, 20]] as $p) { $st->execute($p); var_dump($st->fetchColumn()); }
$st = $db->prepare('select :n::int + :n::int as s, :m::text as m');
$st->execute(['n' => 21, 'm' => 'q']); var_dump($st->fetch(PDO::FETCH_ASSOC));
$st = $db->prepare('select \'a ?? b\' as q, ?::text as r, \'x:y\' as z');
$st->execute(['p']); var_dump($st->fetch(PDO::FETCH_ASSOC));
$st = $db->prepare('select a, b, c from t where c > ? order by a');
$st->execute([3]); var_dump($st->fetchAll(PDO::FETCH_NUM));
$st->execute([5]); var_dump($st->fetchAll(PDO::FETCH_NUM));
var_dump($st->rowCount(), $st->columnCount());

// rowCount of every command kind
foreach (['update t set c = c + 1 where c < 3', 'update t set c = 0 where false', 'delete from t where c = 99', 'select * from t',
          'create temp table u(a int)', 'insert into u select g from generate_series(1,5) g', 'delete from u', 'drop table u'] as $q) {
    $st = $db->prepare($q); $st->execute(); echo $q, ' => ', $st->rowCount(), ' cols=', $st->columnCount(), "\n";
}

// lastInsertId
var_dump($db->lastInsertId(), $db->lastInsertId('t_a_seq'));
t(fn () => $db->lastInsertId('nosuch'));
t(fn () => $db->lastInsertId('t'));

// errors: count mismatch, undefined parameter, mixed, syntax at execute, multi statement, bad cast
$st = $db->prepare('select ?::int + ?::int');
t(fn () => $st->execute([1]));
t(fn () => $st->execute([1, 2, 3]));
t(fn () => $st->execute([1, 2]));
var_dump($st->fetchColumn());
$st = $db->prepare('select :a::int + :b::int');
t(fn () => $st->execute(['a' => 1]));
t(fn () => $st->execute(['a' => 1, 'c' => 2]));
t(fn () => $st->execute(['a' => 1, 'b' => 2]));
try { $db->prepare('select ? , :a'); } catch (PDOException $e) { echo $e->getMessage(), "\n"; } // errorInfo shape differs (task 5)
$st = $db->prepare('selec ?');
echo "prepared\n";
t(fn () => $st->execute([1]));
t(fn () => $db->query('select 1; select 2'));
$st = $db->prepare('select ?::int');
t(fn () => $st->execute(['abc']));
var_dump($st->errorCode(), $st->errorInfo());
var_dump($st->execute([7]), $st->errorCode(), $st->fetchColumn());
t(fn () => $db->query('select nope from t'));
t(fn () => $db->query('select $1'));
var_dump($db->query('select 41 + 1')->fetchColumn());

// a failed statement leaves the connection usable inside a transaction state of its own
$db->beginTransaction();
t(fn () => $db->query('select 1/0'));
var_dump($db->inTransaction());
$db->rollBack();
var_dump($db->inTransaction(), $db->query('select count(*) from t')->fetchColumn());

// statements go away while others live on
for ($i = 0; $i < 30; $i++) { $s = $db->prepare('select ?::int'); $s->execute([$i]); $s->fetchAll(); $s = null; }
var_dump($db->query('select 1')->fetchColumn());
$st = $db->prepare('select ?::int');
$st->execute([1]); $st->closeCursor(); $st->execute([2]); var_dump($st->fetchColumn());
// closed statements are deallocated server-side; live ones are still there
// (a named statement, not a chained temporary: #299 leaks those)
function live(PDO $db): int { $q = $db->query('select count(*) from pg_prepared_statements'); $n = $q->fetchColumn(); $q = null; return $n; }
$base = live($db);
$s1 = $db->prepare('select ?::int'); $s1->execute([1]);
$s2 = $db->prepare('select ?::int'); $s2->execute([2]);
$s3 = $db->prepare('selec 1'); try { $s3->execute(); } catch (PDOException $e) {}
var_dump(live($db) - $base);
$s1 = null;
var_dump(live($db) - $base);
$s2 = null;
var_dump(live($db) - $base);
