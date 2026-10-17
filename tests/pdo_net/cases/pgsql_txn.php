<?php
set_error_handler(function ($n, $s) { if (!(error_reporting() & $n)) { return false; } echo "WARN: $s\n"; return true; });
function pdo(int $mode = PDO::ERRMODE_EXCEPTION): PDO { return new PDO((string) getenv('PDO_PG_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => $mode]); }
function cnt(PDO $db): string { $s = $db->query('select count(*) from t'); return (string) $s->fetchColumn(); }
$db = pdo();
$db->exec('create temp table t(a int)');
var_dump($db->inTransaction());
var_dump($db->beginTransaction(), $db->inTransaction());
try { $db->beginTransaction(); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
$db->exec('insert into t values (1)');
var_dump($db->rollBack(), $db->inTransaction(), cnt($db));
$db->beginTransaction(); $db->exec('insert into t values (2)'); var_dump($db->commit(), $db->inTransaction(), cnt($db));
try { $db->commit(); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
try { $db->rollBack(); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
echo "-- failed transaction (E)\n";
$db->beginTransaction();
try { $db->exec('select 1/0'); } catch (PDOException $e) { echo $e->getMessage(), "\n"; }
var_dump($db->inTransaction());
try { $db->exec('select 1'); } catch (PDOException $e) { echo $e->getMessage(), ' ', $e->errorInfo[0], "\n"; }
$q = $db->prepare('select 1');
try { $q->execute(); } catch (PDOException $e) { echo $e->getMessage(), ' ', $e->errorInfo[0], "\n"; }
var_dump($db->rollBack(), $db->inTransaction(), cnt($db));
echo "-- commit of a failed transaction\n";
$db->beginTransaction();
try { $db->exec('select 1/0'); } catch (PDOException $e) { echo $e->errorInfo[0], "\n"; }
var_dump($db->commit(), $db->inTransaction(), cnt($db));
echo "-- raw BEGIN / COMMIT / savepoints\n";
$db->exec('begin'); var_dump($db->inTransaction());
$db->exec('savepoint a'); $db->exec('insert into t values (3)');
try { $db->exec('select 1/0'); } catch (PDOException $e) { echo $e->errorInfo[0], "\n"; }
$db->exec('rollback to savepoint a'); $db->exec('insert into t values (4)');
$db->exec('commit'); var_dump($db->inTransaction(), cnt($db));

try { $db->exec('begin; select 1/0'); } catch (PDOException $e) { echo $e->errorInfo[0], "\n"; }
var_dump($db->inTransaction()); $db->exec('rollback'); var_dump($db->inTransaction());
echo "-- DDL is transactional\n";
$db->beginTransaction(); $db->exec('create temp table tt(a int)'); $db->rollBack();
try { $db->exec('select * from tt'); } catch (PDOException $e) { echo $e->errorInfo[0], "\n"; }
echo "-- modes\n";
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING] as $m) {
    $d = pdo($m);
    var_dump($d->beginTransaction(), $d->exec('select 1/0'), $d->inTransaction(), $d->exec('select 1'), $d->errorInfo()[0], $d->rollBack(), $d->inTransaction());
}
echo "-- constants\n";
var_dump(@PDO::PGSQL_TRANSACTION_IDLE, @PDO::PGSQL_TRANSACTION_ACTIVE, @PDO::PGSQL_TRANSACTION_INTRANS, @PDO::PGSQL_TRANSACTION_INERROR, @PDO::PGSQL_TRANSACTION_UNKNOWN);
