<?php
// Manticore-only (no php oracle: Async\ is a superset); expectation lives in the sibling .expected file.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
function pdo(array $o = []): PDO { return new PDO((string) getenv("PDO_PG_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }

async(function () {
    $db = pdo();
    $t = spawn(function () use ($db) {
        $st = $db->query('select pg_sleep(5)');
        return 'finished';
    });
    delay(0.1);
    $t->cancel();
    $t->join();
    try {
        $st = $db->query('select 1');
        echo "same pdo: usable\n";
    } catch (PDOException $e) {
        echo "same pdo: PDOException\n";
    }
    $fresh = pdo();
    $st = $fresh->query('select 7');
    echo "fresh: ", $st->fetchColumn(), "\n";
    $n = -1;
    for ($i = 0; $i < 40; $i++) {
        $st = $fresh->query("select count(*) from pg_stat_activity where datname='d' and state='active' and pid <> pg_backend_pid()");
        $n = (int) $st->fetchColumn();
        if ($n === 0) { break; }
        delay(0.05);
    }
    echo "leaked active: $n\n";
});
