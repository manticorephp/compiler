<?php
// servers: mysql
// Manticore-only (Async\ has no php oracle); expectation in the sibling .expected, server mc-my-iso (global Prepared_stmt_count).
// A statement destroyed while ANOTHER fiber's cycle holds the connection: its COM_STMT_CLOSE is queued, sent when that cycle ends,
// the server really deallocates it, and the stream stays in sync.
use function Async\async;
use function Async\spawn;
use function Async\delay;
function pdo(bool $native): PDO { return new PDO((string) getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => !$native]); }
function cnt(PDO $mon): int { $s = $mon->query("show global status like 'Prepared_stmt_count'"); $r = $s->fetch(PDO::FETCH_NUM); return (int) $r[1]; }

async(function () {
    $mon = pdo(false);
    $base = cnt($mon);
    $db = pdo(true);
    $keep = $db->prepare('select sleep(?)');
    $victim = $db->prepare('select 5');
    echo 'prepared: ', cnt($mon) - $base, "\n";
    $t = spawn(function () use ($keep) {
        $keep->execute([1]);
        echo 'sleep done: ', $keep->fetchColumn(), "\n";
    });
    delay(0.3);
    $victim = null;
    echo 'destroyed while in flight: ', cnt($mon) - $base, "\n";
    $t->join();
    $until = microtime(true) + 5.0;
    while (cnt($mon) - $base > 1 && microtime(true) < $until) { delay(0.02); }
    echo 'after the cycle: ', cnt($mon) - $base, "\n";
    $q = $db->query('select 42');
    echo 'in sync: ', $q->fetchColumn(), "\n";
    $q = null;
    $keep = null;
    $t = null;
    echo 'all released: ', cnt($mon) - $base, "\n";
});
