<?php
// Manticore-only (no php oracle: Async\ is a superset); expectation lives in the sibling .expected file.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
function pdo(array $o = []): PDO { return new PDO((string) getenv("PDO_MY_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
async(function () {
    $db = pdo([PDO::ATTR_TIMEOUT => 1]);
    $id = (int) $db->query('select connection_id()')->fetchColumn();
    $t0 = microtime(true);
    try {
        $st = $db->query('select sleep(3)');
        echo "no error\n";
    } catch (PDOException $e) {
        echo "PDOException ", $e->errorInfo[1], "\n";
    }
    $s = microtime(true) - $t0;
    echo $s >= 0.9 && $s < 3.0 ? "about 1s\n" : "elapsed $s\n";
    try { $db->query('select 1'); echo "usable\n"; } catch (PDOException $e) { echo "dead\n"; }
    $fresh = pdo();
    $n = -1;
    for ($i = 0; $i < 20; $i++) {
        $st = $fresh->prepare("select count(*) from information_schema.processlist where id = ? and command = 'Query'");
        $st->execute([$id]);
        $n = (int) $st->fetchColumn();
        if ($n === 0) { break; }
        delay(0.05);
    }
    echo "leaked running: $n\n";
});
