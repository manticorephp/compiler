<?php
// Manticore-only (Async\ is a superset); expectation lives in the sibling .expected file.
// An open unbuffered cursor holds the connection: its owner gets php's 2014, another task waits its turn.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
function pdo(array $o = []): PDO { return new PDO((string) getenv("PDO_MY_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 1000 => false]); }
async(function () {
    $db = pdo();
    $log = [];
    $a = spawn(function () use ($db, &$log) {
        $s = $db->query('select n, sleep(0.1) from (select 1 n union all select 2 union all select 3) x');
        $log[] = 'a open';
        try { $db->query('select 99'); $log[] = 'a second query ok'; }
        catch (PDOException $e) { $log[] = 'a second query: ' . $e->errorInfo[1]; }
        while (($r = $s->fetch(PDO::FETCH_NUM)) !== false) { $log[] = 'a row ' . $r[0]; }
        $log[] = 'a done';
        return 1;
    });
    $b = spawn(function () use ($db, &$log) {
        delay(0.05);
        $log[] = 'b asks';
        $q = $db->query('select 7');
        $v = $q->fetchColumn();
        $log[] = 'b got ' . $v;
        return $v;
    });
    awaitAll($a, $b);
    echo implode("\n", $log), "\n";

    // the waiter is bounded by ATTR_TIMEOUT, not parked forever behind a cursor nobody reads
    $db = pdo([PDO::ATTR_TIMEOUT => 1]);
    $s = $db->query('select 1 union select 2');
    $s->fetch();
    $w = spawn(function () use ($db) {
        try { $db->query('select 8'); return 'waiter ran'; }
        catch (PDOException $e) { return 'waiter: ' . $e->getMessage(); }
    });
    echo $w->await(), "\n";
    $s->closeCursor();
    $q = $db->query('select 9'); echo "after: ", $q->fetchColumn(), "\n";
});
