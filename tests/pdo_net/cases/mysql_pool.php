<?php
// Manticore-only (no php oracle: Async\ is a superset); expectation lives in the sibling .expected file.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
use Async\Pool;
function dsn(): string { return (string) getenv("PDO_MY_DSN"); }
function opts(): array { return [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]; }
function user(): string { return getenv("PDO_USER") ?: "u"; }
function pass(): string { return getenv("PDO_PASS") ?: "pw"; }
async(function () {
    // 100 tasks x sleep(0.05) on 10 connections: ~0.5 s, serial would be 5 s
    $pool = Pool::pdo(dsn(), user(), pass(), opts(), 10);
    $t0 = microtime(true);
    $tasks = [];
    for ($i = 0; $i < 100; $i++) {
        $tasks[] = spawn(fn() => $pool->with(fn(PDO $db) => 1 + (int) $db->query('select sleep(0.05)')->fetchColumn()));
    }
    $res = awaitAll(...$tasks);
    $ms = (microtime(true) - $t0) * 1000;
    echo array_sum($res), "\n";
    echo $ms < 3000 ? "pooled" : "serial", "\n";
    $s = $pool->stats();
    echo "created=", $s['created'] <= 10 ? "<=10" : $s['created'], " busy=", $s['busy'], " waiting=", $s['waiting'], "\n";
    $pool->close();

    // a transaction, session variables and temp tables a task leaves behind are gone before the next acquirer sees the connection
    $pool = Pool::pdo(dsn(), user(), pass(), opts(), 1);
    $id = $pool->with(function (PDO $db) {
        $db->exec('create temporary table pool_t (x int)');
        $db->exec('set @leak = 5');
        $db->beginTransaction();
        $db->exec('insert into pool_t values (1)');
        $st = $db->prepare('select ?');
        $st->execute([1]);
        return (int) $db->query('select connection_id()')->fetchColumn();
    });
    $r = $pool->with(function (PDO $db) {
        $id = (int) $db->query('select connection_id()')->fetchColumn();
        try { $db->query('select * from pool_t')->fetchAll(); $tbl = true; } catch (PDOException $e) { $tbl = false; }
        $st = $db->prepare('select 41 + ?');
        $st->execute([1]);
        return [$id, $db->inTransaction(), $tbl, $db->query('select @leak')->fetchColumn(), (int) $st->fetchColumn(), $db->query('select @@autocommit')->fetchColumn(), $db->query('select @@character_set_client')->fetchColumn()];
    });
    echo "same connection=", $r[0] === $id ? "yes" : "no", " inTransaction=", var_export($r[1], true), " tempTable=", var_export($r[2], true),
        " leak=", var_export($r[3], true), " prepared=", $r[4], " autocommit=", $r[5], " charset=", $r[6], "\n";
    $pool->close();

    // a killed connection is found by ping and replaced
    $pool = Pool::pdo(dsn(), user(), pass(), opts(), 1);
    $id = $pool->with(fn(PDO $db) => (int) $db->query('select connection_id()')->fetchColumn());
    $admin = new PDO(dsn(), user(), pass(), opts());
    $admin->exec('kill ' . $id);
    delay(0.2);
    $id2 = $pool->with(fn(PDO $db) => (int) $db->query('select connection_id()')->fetchColumn());
    $s = $pool->stats();
    echo "replaced=", $id2 !== $id ? "yes" : "no", " created=", $s['created'], " discarded=", $s['discarded'], "\n";
    $pool->close();

    // resetSession() throws in EVERY error mode; a pool whose reset fails discards the connection
    $silent = [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT];
    $db = new PDO(dsn(), user(), pass(), $silent);
    $admin->exec("kill " . (int) $db->query("select connection_id()")->fetchColumn());
    delay(0.2);
    try { $db->resetSession(); echo "silent reset: MISSING\n"; } catch (PDOException $e) { echo "silent reset threw\n"; }
    $pool = Pool::pdo(dsn(), user(), pass(), $silent, 1);
    $pool->with(function (PDO $d) use ($admin) {
        $admin->exec("kill " . (int) $d->query("select connection_id()")->fetchColumn());
        delay(0.2);
    });
    $s = $pool->stats();
    echo "failed reset: idle=", $s["idle"], " discarded=", $s["discarded"], "\n";
    echo "next ok=", $pool->with(fn(PDO $d) => (int) $d->query("select 1")->fetchColumn()), "\n";
    $pool->close();

    // a reset returns the session to its CONSTRUCTION state: DSN charset and ATTR_AUTOCOMMIT
    $pool = Pool::pdo(dsn() . ";charset=latin1", user(), pass(), opts() + [PDO::ATTR_AUTOCOMMIT => false, PDO::MYSQL_ATTR_INIT_COMMAND => "set @init = 7"], 1);
    $probe = fn(PDO $d) => $d->query("select @@character_set_client, @@autocommit, @init")->fetch(PDO::FETCH_NUM);
    $a = $pool->with(function (PDO $d) use ($probe) {
        $before = $probe($d);
        $d->exec("set names utf8mb4");
        $d->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
        $d->exec("set @init = 0");
        return $before;
    });
    $b = $pool->with(fn(PDO $d) => [$probe($d), $d->getAttribute(PDO::ATTR_AUTOCOMMIT)]);
    echo "first: ", implode(",", $a), "\n";
    echo "after reset: ", implode(",", $b[0]), " attr=", var_export($b[1], true), "\n";
    $pool->close();

    // PDO::ping / resetSession on a plain connection, an unbuffered cursor is closed by the reset
    $db = new PDO(dsn(), user(), pass(), opts() + [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
    echo "ping=", var_export($db->ping(), true), "\n";
    $st = $db->query('select 1 union select 2 union select 3');
    $st->fetch();
    $db->resetSession();
    echo "reset with open cursor: ", $db->query('select 9')->fetchAll(PDO::FETCH_COLUMN)[0], "\n";
    $db->beginTransaction();
    $db->resetSession();
    echo "reset: inTransaction=", var_export($db->inTransaction(), true), "\n";
    $admin->exec('kill ' . (int) $db->query('select connection_id()')->fetchColumn());
    delay(0.1);
    echo "dead ping=", var_export($db->ping(), true), "\n";
});
