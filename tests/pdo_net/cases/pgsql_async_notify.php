<?php
// Manticore-only (no php oracle: Async\ is a superset); expectation lives in the sibling .expected file.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
function pdo(array $o = []): PDO { return new PDO((string) getenv("PDO_PG_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }

async(function () {
    $a = pdo();
    $b = pdo();
    $a->exec('LISTEN mc_t9_async');

    // 1. a fiber parked in getNotify wakes on the notification while a sibling keeps running
    $ticks = 0;
    $atWake = -1;
    $got = null;
    $t0 = microtime(true);
    $waiter = spawn(function () use ($a, &$got, &$atWake, &$ticks) {
        $got = $a->getNotify(PDO::FETCH_ASSOC, 10000);
        $atWake = $ticks;
    });
    $ticker = spawn(function () use (&$ticks) {
        for ($i = 0; $i < 15; $i++) { delay(0.02); $ticks++; }
    });
    delay(0.15);
    $b->exec("NOTIFY mc_t9_async, 'wake'");
    awaitAll($waiter, $ticker);
    echo 'payload ', $got['payload'] ?? 'none', ' from ', ($got['pid'] ?? 0) === $b->getPid() ? 'B' : 'other', "\n";
    echo 'sibling ran while parked: ', $atWake >= 4 ? 'yes' : 'no (' . $atWake . ')', "\n";
    echo 'woke promptly: ', microtime(true) - $t0 < 3.0 ? 'yes' : 'no', "\n";

    // 2. a timeout returns false after about the time, with the loop alive meanwhile
    $ticks = 0;
    $res = 'unset';
    $t1 = microtime(true);
    $w = spawn(function () use ($a, &$res) { $res = $a->getNotify(PDO::FETCH_ASSOC, 300); });
    $tk = spawn(function () use (&$ticks) { for ($i = 0; $i < 10; $i++) { delay(0.02); $ticks++; } });
    awaitAll($w, $tk);
    $el = microtime(true) - $t1;
    echo 'timeout: ', var_export($res, true), ' ', $el >= 0.25 && $el < 2.0 ? 'on time' : 'off (' . $el . ')', ', ticks ', $ticks, "\n";

    // 3. two waiters on two connections each get their own channel's message
    $c = pdo();
    $c->exec('LISTEN mc_t9_async_c');
    $r1 = null;
    $r2 = null;
    $w1 = spawn(function () use ($a, &$r1) { $r1 = $a->getNotify(PDO::FETCH_NUM, 5000); });
    $w2 = spawn(function () use ($c, &$r2) { $r2 = $c->getNotify(PDO::FETCH_NUM, 5000); });
    delay(0.1);
    $b->exec("NOTIFY mc_t9_async_c, 'for-c'");
    delay(0.05);
    $b->exec("NOTIFY mc_t9_async, 'for-a'");
    awaitAll($w1, $w2);
    echo 'a got ', $r1[2] ?? 'none', ', c got ', $r2[2] ?? 'none', "\n";

    // 4. cancelling a parked waiter leaves the connection usable and loses nothing
    $w = spawn(function () use ($a) { $a->getNotify(PDO::FETCH_ASSOC, 10000); echo "not reached\n"; });
    delay(0.1);
    $w->cancel();
    $w->join();
    echo 'after cancel: ', $a->query('select 5')->fetchColumn(), "\n";
    $b->exec("NOTIFY mc_t9_async, 'late'");
    echo 'late: ', $a->getNotify(PDO::FETCH_ASSOC, 2000)['payload'] ?? 'none', "\n";

    // 5. a notification that arrives during a query in another fiber is queued for the waiter
    $q = spawn(function () use ($a) { return $a->query('select pg_sleep(0.3), 9')->fetch(PDO::FETCH_NUM)[1]; });
    delay(0.1);
    $b->exec("NOTIFY mc_t9_async, 'during'");
    [$qr] = awaitAll($q);
    echo 'query result: ', $qr, "\n";
    echo 'queued: ', $a->getNotify(PDO::FETCH_ASSOC, 2000)['payload'] ?? 'none', "\n";
});
