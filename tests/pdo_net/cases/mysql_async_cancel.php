<?php
// Manticore-only (no php oracle: Async\ is a superset); expectation lives in the sibling .expected file.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
function pdo(array $o = []): PDO { return new PDO((string) getenv("PDO_MY_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
async(function () {
    $db = pdo();
    $id = (int) $db->query('select connection_id()')->fetchColumn();
    $t = spawn(function () use ($db) {
        $st = $db->query('select sleep(5)');
        return 'finished';
    });
    delay(0.1);
    $t->cancel();
    $t->join();
    try {
        $st = $db->query('select 1');
        echo "same pdo: usable\n";
    } catch (PDOException $e) {
        echo "same pdo: PDOException ", $e->errorInfo[1], "\n";
    }
    try { $db->exec('set @a = 1'); echo "same pdo, again: usable\n"; } catch (PDOException $e) { echo "same pdo, again: PDOException ", $e->errorInfo[1], "\n"; }
    $fresh = pdo();
    echo "fresh: ", $fresh->query('select 7')->fetchColumn(), "\n";
    // KILL QUERY really happened: no sleep(5) is still running for that connection id
    $n = -1;
    for ($i = 0; $i < 40; $i++) {
        $st = $fresh->prepare("select count(*) from information_schema.processlist where id = ? and command = 'Query'");
        $st->execute([$id]);
        $n = (int) $st->fetchColumn();
        if ($n === 0) { break; }
        delay(0.05);
    }
    echo "leaked running: $n\n";
});
