<?php
// Manticore-only (no php oracle: Async\ is a superset); expectation lives in the sibling .expected file.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
use Async\Pool;
function dsn(): string { return (string) getenv("PDO_PG_DSN"); }
function opts(): array { return [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]; }
async(function () {
    // 100 tasks x pg_sleep(0.05) on 10 connections: ~0.5 s, serial would be 5 s
    $pool = Pool::pdo(dsn(), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", opts(), 10);
    $t0 = microtime(true);
    $tasks = [];
    for ($i = 0; $i < 100; $i++) {
        $tasks[] = spawn(fn() => $pool->with(fn(PDO $db) => (int) $db->query('select 1 from pg_sleep(0.05)')->fetchColumn()));
    }
    $res = awaitAll(...$tasks);
    $ms = (microtime(true) - $t0) * 1000;
    echo array_sum($res), "\n";
    echo $ms < 3000 ? "pooled" : "serial", "\n";
    $s = $pool->stats();
    echo "created=", $s['created'] <= 10 ? "<=10" : $s['created'], " busy=", $s['busy'], " waiting=", $s['waiting'], "\n";
    $pool->close();

    // a transaction (and session state) a task leaves behind is gone before the next acquirer sees the connection
    $pool = Pool::pdo(dsn(), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", opts(), 1);
    $pid = $pool->with(function (PDO $db) {
        $db->exec('create temp table pool_t (x int)');
        $db->beginTransaction();
        $db->exec('insert into pool_t values (1)');
        $db->exec("set application_name = 'leaked'");
        return (int) $db->query('select pg_backend_pid()')->fetchColumn();
    });
    $r = $pool->with(function (PDO $db) {
        $pid = (int) $db->query('select pg_backend_pid()')->fetchColumn();
        $tbl = $db->query("select to_regclass('pg_temp.pool_t') is not null")->fetchColumn();
        return [$pid, $db->inTransaction(), $tbl, $db->query('show application_name')->fetchColumn()];
    });
    echo "same connection=", $r[0] === $pid ? "yes" : "no", " inTransaction=", var_export($r[1], true), " tempTable=", var_export((bool) $r[2], true), " application_name='", $r[3], "'\n";
    $pool->close();

    // a killed backend is found by ping and replaced
    $pool = Pool::pdo(dsn(), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", opts(), 1);
    $pid = $pool->with(fn(PDO $db) => (int) $db->query('select pg_backend_pid()')->fetchColumn());
    $admin = new PDO(dsn(), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", opts());
    $admin->query('select pg_terminate_backend(' . $pid . ')')->fetchAll();
    delay(0.2);
    $pid2 = $pool->with(fn(PDO $db) => (int) $db->query('select pg_backend_pid()')->fetchColumn());
    $s = $pool->stats();
    echo "replaced=", $pid2 !== $pid ? "yes" : "no", " created=", $s['created'], " discarded=", $s['discarded'], "\n";
    $pool->close();

    // resetSession() throws in EVERY error mode; a pool whose reset fails discards the connection
    $silent = [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT];
    $admin = new PDO(dsn(), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", opts());
    $db = new PDO(dsn(), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $silent);
    $admin->query("select pg_terminate_backend(" . (int) $db->query("select pg_backend_pid()")->fetchColumn() . ")")->fetchAll();
    delay(0.2);
    try { $db->resetSession(); echo "silent reset: MISSING\n"; } catch (PDOException $e) { echo "silent reset threw\n"; }
    $pool = Pool::pdo(dsn(), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $silent, 1);
    $pool->with(function (PDO $d) use ($admin) {
        $admin->query("select pg_terminate_backend(" . (int) $d->query("select pg_backend_pid()")->fetchColumn() . ")")->fetchAll();
        delay(0.2);
    });
    $s = $pool->stats();
    echo "failed reset: idle=", $s["idle"], " discarded=", $s["discarded"], "\n";
    echo "next ok=", $pool->with(fn(PDO $d) => (int) $d->query("select 1")->fetchColumn()), "\n";
    $pool->close();

    // PDO::ping / resetSession on a plain connection
    $db = new PDO(dsn(), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", opts());
    echo "ping=", var_export($db->ping(), true), "\n";
    $db->beginTransaction();
    $db->resetSession();
    echo "reset: inTransaction=", var_export($db->inTransaction(), true), "\n";
    try { $db->query('select pg_terminate_backend(pg_backend_pid())'); } catch (PDOException $e) {}
    delay(0.1);
    echo "dead ping=", var_export($db->ping(), true), "\n";
});
