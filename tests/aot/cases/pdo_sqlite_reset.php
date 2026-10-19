<?php
// PDO::resetSession() / PDO::ping() / Async\Pool::pdo (superset, no Zend oracle). Hand-written expectation.
use function Async\async;
use Async\Pool;

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('create table t (x int)');
var_dump($db->ping());
$db->beginTransaction();
$db->exec('insert into t values (1)');
$db->resetSession();
var_dump($db->inTransaction());
echo $db->query('select count(*) from t')->fetchColumn(), "\n";
$db->resetSession();
echo "idle reset ok\n";

async(function () {
    $pool = Pool::pdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], 2);
    $r = $pool->with(function (PDO $d) {
        $d->exec('create table k (v int)');
        $d->beginTransaction();
        $d->exec('insert into k values (7)');
        return 1;
    });
    $n = $pool->with(fn(PDO $d) => $d->inTransaction() ? 'in-txn' : (string) $d->query('select count(*) from k')->fetchColumn());
    echo "pool: ", $n, "\n";
    $pool->close();
});
