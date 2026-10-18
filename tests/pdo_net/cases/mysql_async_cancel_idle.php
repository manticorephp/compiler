<?php
// Manticore-only (no php oracle: Async\ is a superset); expectation lives in the sibling .expected file.
// A fiber cancelled during an UNBOUNDED idle wait (the server is running `select sleep(5)`, no ATTR_TIMEOUT set) is released at
// once and poisons its connection. The cancel hook then runs KILL QUERY from a second connection (see mysql_async_cancel).
use function Async\async;
use function Async\spawn;
use function Async\delay;
function pdo(array $o = []): PDO { return new PDO((string) getenv("PDO_MY_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }

async(function () {
    $db = pdo();
    $t0 = microtime(true);
    $t = spawn(function () use ($db) {
        $st = $db->query('select sleep(5)');
        return 'finished';
    });
    delay(0.2);
    $t->cancel();
    $t->join();
    echo "released promptly: ", microtime(true) - $t0 < 3.0 ? "yes" : "NO", "\n";
    try {
        $st = $db->query('select 1');
        echo "same pdo: usable\n";
    } catch (PDOException $e) {
        echo "same pdo: PDOException\n";
    }
    try {
        $db->exec('set @a = 1');
        echo "same pdo, again: usable\n";
    } catch (PDOException $e) {
        echo "same pdo, again: PDOException ", $e->errorInfo[1], "\n";
    }
    $fresh = pdo();
    $st = $fresh->query('select 7');
    echo "fresh: ", $st->fetchColumn(), "\n";
    $db = null;
    echo "poisoned handle released\n";
});
