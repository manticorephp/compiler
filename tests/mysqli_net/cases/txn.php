<?php
// Transactions: autocommit, begin_transaction with every MYSQLI_TRANS_START_* flag, commit/rollback with every chain/release
// combination, nested savepoints, read-only transactions, a snapshot seen from a second connection, argument errors, and the
// state each command leaves in the connection.
error_reporting(0);
mysqli_report(MYSQLI_REPORT_OFF);
function conn(): mysqli
{
    return new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
}
function st(mysqli $m): string
{
    return 'ar=' . json_encode($m->affected_rows) . ' id=' . json_encode($m->insert_id) . ' info=' . json_encode($m->info) . ' fc=' . $m->field_count . ' wc=' . $m->warning_count . ' errno=' . $m->errno . ' ' . $m->error . ' [' . $m->sqlstate . ']';
}
function cnt(mysqli $m, string $where = '1'): string
{
    $r = $m->query("SELECT COUNT(*) FROM mc_t8_txn WHERE $where");
    return $r ? (string) $r->fetch_row()[0] : 'err ' . $m->errno;
}
function t(string $label, callable $f): void
{
    try {
        $r = $f();
        echo $label, ': ', json_encode($r), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$a = conn();
$b = conn();
$a->query('DROP TABLE IF EXISTS mc_t8_txn');
$a->query('CREATE TABLE mc_t8_txn (id INT AUTO_INCREMENT PRIMARY KEY, tag VARCHAR(40)) ENGINE=InnoDB');

echo "-- autocommit\n";
t('autocommit(false)', fn() => $a->autocommit(false));
echo st($a), "\n";
t('@@autocommit', fn() => $a->query('SELECT @@autocommit')->fetch_row());
$a->query("INSERT INTO mc_t8_txn (tag) VALUES ('ac0')");
t('B sees', fn() => cnt($b));
t('rollback', fn() => $a->rollback());
t('A after rollback', fn() => cnt($a));
$a->query("INSERT INTO mc_t8_txn (tag) VALUES ('ac0')");
t('commit', fn() => $a->commit());
t('B sees', fn() => cnt($b));
t('autocommit(true)', fn() => $a->autocommit(true));
$a->query("INSERT INTO mc_t8_txn (tag) VALUES ('ac1')");
t('B sees', fn() => cnt($b));
t('mysqli_autocommit', fn() => mysqli_autocommit($a, true));
$a->query('TRUNCATE mc_t8_txn');

echo "-- nested savepoints\n";
t('begin', fn() => $a->begin_transaction());
echo st($a), "\n";
$a->query("INSERT INTO mc_t8_txn (tag) VALUES ('one')");
t('savepoint a', fn() => $a->savepoint('a'));
echo st($a), "\n";
$a->query("INSERT INTO mc_t8_txn (tag) VALUES ('two')");
t('savepoint b', fn() => $a->savepoint('b'));
$a->query("INSERT INTO mc_t8_txn (tag) VALUES ('three')");
t('savepoint c', fn() => $a->savepoint('c'));
$a->query("INSERT INTO mc_t8_txn (tag) VALUES ('four')");
t('count', fn() => cnt($a));
t('rollback to b', fn() => $a->query('ROLLBACK TO SAVEPOINT b'));
t('count', fn() => cnt($a));
t('rollback to c (gone)', fn() => $a->query('ROLLBACK TO SAVEPOINT c'));
echo st($a), "\n";
t('release b', fn() => $a->release_savepoint('b'));
echo st($a), "\n";
t('release b again', fn() => $a->release_savepoint('b'));
echo st($a), "\n";
t('release a', fn() => $a->release_savepoint('a'));
t('release a again', fn() => $a->release_savepoint('a'));
t('B sees', fn() => cnt($b));
t('rollback', fn() => $a->rollback());
t('A', fn() => cnt($a));
t('savepoint replaced', function () use ($a) {
    $a->begin_transaction();
    $a->savepoint('s');
    $a->query("INSERT INTO mc_t8_txn (tag) VALUES ('x')");
    $a->savepoint('s');
    $a->query("INSERT INTO mc_t8_txn (tag) VALUES ('y')");
    $a->query('ROLLBACK TO SAVEPOINT s');
    $n = cnt($a);
    $a->rollback();
    return $n;
});
t('savepoint outside a transaction (autocommit)', fn() => $a->savepoint('z'));
t('release outside', fn() => $a->release_savepoint('z'));
foreach (['a b', 'a`b', '0', 'a,b', "a\0b", 'é', str_repeat('n', 64), str_repeat('n', 65)] as $n) {
    $a->begin_transaction();
    t('savepoint ' . json_encode($n), fn() => $a->savepoint($n));
    t('release ' . json_encode($n), fn() => $a->release_savepoint($n));
    echo '  ', st($a), "\n";
    $a->rollback();
}
t('savepoint empty', fn() => $a->savepoint(''));
t('release empty', fn() => $a->release_savepoint(''));
t('mysqli_savepoint empty', fn() => mysqli_savepoint($a, ''));
t('mysqli_release_savepoint empty', fn() => mysqli_release_savepoint($a, ''));
t('mysqli_savepoint', fn() => mysqli_savepoint($a, 'p'));
t('mysqli_release_savepoint', fn() => mysqli_release_savepoint($a, 'p'));

echo "-- begin_transaction flags\n";
foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 15, 16, 255, 1000, 4294967295, 4294967296, PHP_INT_MAX, -1, -2147483648, PHP_INT_MIN] as $f) {
    $c = conn();
    t("begin_transaction($f)", fn() => $c->begin_transaction($f));
    $c->query('SELECT COUNT(*) FROM mc_t8_txn')->fetch_row();
    t('  insert', fn() => $c->query("INSERT INTO mc_t8_txn (tag) VALUES ('flag')") ? 'ok' : $c->errno . ' ' . $c->error);
    $c->rollback();
    $c->close();
}
t('begin_transaction name empty', fn() => $a->begin_transaction(0, ''));
t('begin_transaction flags and empty name', fn() => $a->begin_transaction(-1, ''));
t('mysqli_begin_transaction flags', fn() => mysqli_begin_transaction($a, -1));
t('mysqli_begin_transaction name', fn() => mysqli_begin_transaction($a, 0, ''));
t('mysqli_begin_transaction', fn() => mysqli_begin_transaction($a, MYSQLI_TRANS_START_READ_ONLY, 'n'));
$a->rollback();

echo "-- read only\n";
t('begin ro', fn() => $a->begin_transaction(MYSQLI_TRANS_START_READ_ONLY));
t('select', fn() => cnt($a));
t('insert', fn() => $a->query("INSERT INTO mc_t8_txn (tag) VALUES ('ro')"));
echo st($a), "\n";
t('commit', fn() => $a->commit());
t('insert after', fn() => $a->query("INSERT INTO mc_t8_txn (tag) VALUES ('rw')"));
t('begin rw', fn() => $a->begin_transaction(MYSQLI_TRANS_START_READ_WRITE));
t('insert', fn() => $a->query("INSERT INTO mc_t8_txn (tag) VALUES ('rw2')"));
t('rollback', fn() => $a->rollback());
t('SET TRANSACTION READ ONLY', fn() => $a->query('SET TRANSACTION READ ONLY'));
t('begin plain', fn() => $a->begin_transaction());
t('insert (session default is rw for START TRANSACTION)', fn() => $a->query("INSERT INTO mc_t8_txn (tag) VALUES ('rw3')"));
$a->rollback();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
t('begin ro (strict)', fn() => $a->begin_transaction(MYSQLI_TRANS_START_READ_ONLY));
t('insert in ro (strict)', fn() => $a->query("INSERT INTO mc_t8_txn (tag) VALUES ('ro')"));
echo st($a), "\n";
$a->rollback();
mysqli_report(MYSQLI_REPORT_OFF);
$a->query('TRUNCATE mc_t8_txn');

echo "-- consistent snapshot\n";
$b->query("INSERT INTO mc_t8_txn (tag) VALUES ('b0')");
t('A begin snapshot', fn() => $a->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT));
$b->query("INSERT INTO mc_t8_txn (tag) VALUES ('b1')");
t('A sees (snapshot)', fn() => cnt($a));
$a->commit();
t('A sees after commit', fn() => cnt($a));
t('A begin plain', fn() => $a->begin_transaction());
t('A sees (first read)', fn() => cnt($a));
$b->query("INSERT INTO mc_t8_txn (tag) VALUES ('b2')");
t('A sees (still)', fn() => cnt($a));
$a->commit();
$a->query('TRUNCATE mc_t8_txn');

echo "-- commit / rollback flags (chain, release)\n";
foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 255, -1] as $f) {
    foreach (['commit', 'rollback'] as $op) {
        $c = conn();
        $c->query('TRUNCATE mc_t8_txn');
        $c->begin_transaction();
        $c->query("INSERT INTO mc_t8_txn (tag) VALUES ('pre')");
        $r = $c->$op($f);
        $o = "$op($f): " . json_encode($r) . ' | ' . st($c);
        $r2 = $c->query("INSERT INTO mc_t8_txn (tag) VALUES ('post')");
        $o .= ' | post ' . json_encode($r2) . ' ' . $c->errno;
        $o .= ' | B sees pre=' . cnt($b, "tag='pre'") . ' post=' . cnt($b, "tag='post'");
        $c->rollback();
        $o .= ' | after rollback B post=' . cnt($b, "tag='post'");
        echo $o, "\n";
        $c->close();
    }
}
t('mysqli_commit', fn() => mysqli_commit($a, MYSQLI_TRANS_COR_AND_CHAIN));
t('mysqli_rollback', fn() => mysqli_rollback($a, MYSQLI_TRANS_COR_AND_NO_CHAIN));
$a->rollback();

echo "-- transaction names do not change the effect\n";
foreach (['n', '', 'a b', 'x/*y*/z', "q\0r", 'é'] as $n) {
    $c = conn();
    t('begin ' . json_encode($n), fn() => $c->begin_transaction(0, $n));
    $c->query("INSERT INTO mc_t8_txn (tag) VALUES ('nm')");
    t('rollback ' . json_encode($n), fn() => $c->rollback(0, $n));
    t('commit ' . json_encode($n), fn() => $c->commit(0, $n));
    $c->close();
}

echo "-- a failed command keeps the rest of the state\n";
$a->query("INSERT INTO mc_t8_txn (tag) VALUES ('s')");
$a->query('SELECT 1/0, 2');
echo st($a), "\n";
t('release_savepoint nope', fn() => $a->release_savepoint('nope'));
echo st($a), "\n";
t('commit', fn() => $a->commit());
echo st($a), "\n";

echo "-- strict mode\n";
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
t('release_savepoint nope', fn() => $a->release_savepoint('nope'));
t('savepoint bad name', fn() => $a->savepoint('a`b'));
t('commit RELEASE|CHAIN (syntax error)', fn() => $a->commit(MYSQLI_TRANS_COR_AND_CHAIN | MYSQLI_TRANS_COR_RELEASE));
t('autocommit', fn() => $a->autocommit(true));
mysqli_report(MYSQLI_REPORT_OFF);

echo "-- out of sync\n";
$a->real_query('SELECT 1');
foreach (['autocommit' => fn() => $a->autocommit(true), 'begin_transaction' => fn() => $a->begin_transaction(), 'commit' => fn() => $a->commit(),
    'rollback' => fn() => $a->rollback(), 'savepoint' => fn() => $a->savepoint('x'), 'release_savepoint' => fn() => $a->release_savepoint('x')] as $n => $f) {
    t($n, $f);
    echo '  ', st($a), "\n";
}
$a->store_result();

echo "-- closed / not initialised\n";
$c = conn();
$c->close();
$i = new mysqli();
foreach (['closed' => $c, 'init' => $i] as $k => $o) {
    t("$k autocommit", fn() => $o->autocommit(true));
    t("$k begin_transaction", fn() => $o->begin_transaction());
    t("$k begin_transaction -1", fn() => $o->begin_transaction(-1));
    t("$k commit", fn() => $o->commit());
    t("$k rollback", fn() => $o->rollback());
    t("$k savepoint", fn() => $o->savepoint('a'));
    t("$k savepoint empty", fn() => $o->savepoint(''));
    t("$k release_savepoint", fn() => $o->release_savepoint('a'));
}
$a->query('DROP TABLE mc_t8_txn');
echo "done\n";
