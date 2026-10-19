<?php
// Manticore-only (no php oracle: Async\ is a superset); expectation lives in the sibling .expected file.
use function Async\async;
use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;
function pdo(array $o = []): PDO { return new PDO((string) getenv("PDO_PG_DSN"), getenv("PDO_USER") ?: "u", getenv("PDO_PASS") ?: "pw", $o + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }

async(function () {
    $db = pdo();
    $tasks = [];
    for ($i = 0; $i < 10; $i++) {
        $tasks[] = spawn(function () use ($db, $i) {
            $sql = $i % 4 === 0 ? "select pg_sleep(0.05), $i as v" : "select $i as v";
            $st = $db->query($sql);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return (int) $row["v"] === $i ? "ok" : "BAD $i got " . $row["v"];
        });
    }
    $res = awaitAll(...$tasks);
    echo implode(",", $res), "\n";
    $st = $db->query('select 42');
    echo $st->fetchColumn(), "\n";
});
